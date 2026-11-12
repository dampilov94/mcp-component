<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Kind\Kind;
use ModxMCP\Kind\Objects;
use ModxMCP\Kind\PackageKind;
use ModxMCP\Kind\ProviderKind;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class InstallPackageAction extends WriteAction {
    const WARNING = 'Installing runs the package\'s own scripts and resolvers on this site. It cannot be undone with modx_undo; '
        . 'the package can only be removed with modx_uninstall_package. Repeat with confirm: true to install.';

    public function name() { return 'install_package'; }
    public function group() { return 'package_management'; }
    public function scope() { return Scope::ADMIN; }
    public function destructive() { return true; }
    public function reversible() { return false; }
    public function openWorld() { return true; }

    public function description() {
        return 'Install or upgrade a package: give the signature of one already downloaded (modx_list kind package), or a package_name '
            . '(with optional provider id and version) to find on a provider and download. Without confirm: true it only searches '
            . 'and returns the plan. Irreversible: the package\'s resolvers run, modx_undo cannot revert it; only modx_uninstall_package '
            . 'removes it. The result lists the error-log entries written during the install. modxMCP itself cannot be installed this way.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'signature'    => Schema::str('Signature of a package that is already downloaded, e.g. pdotools-2.13.2-pl.', array('minLength' => 1, 'maxLength' => 191)),
            'package_name' => Schema::str('Name of a package to find on the provider; the newest compatible version is used unless version is given.', array('minLength' => 1, 'maxLength' => 191)),
            'provider'     => Schema::int('Provider id; default is the provider named modx.com, else the first one.', array('minimum' => 1)),
            'version'      => Schema::str('Version to install, e.g. 2.13.2-pl or 2.13.2; with package_name only.', array('minLength' => 1, 'maxLength' => 100)),
            'confirm'      => Schema::bool('Actually download and install. Without it only the plan is returned.'),
        ));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'performed'      => Schema::bool(),
            'signature'      => Schema::str(),
            'package_name'   => Schema::str(),
            'version'        => Schema::str(),
            'needs_download' => Schema::bool(),
            'installed'      => Schema::str(),
            'changeset_id'   => array('type' => array('integer', 'null')),
            'reversible'     => Schema::bool(),
            'log'            => Schema::obj(array(), array(), true),
        ), array('performed', 'signature'), true);
    }

    private function refuseOwn($signature, $name) {
        if (PackageKind::isOwn($signature, $name)) { throw new ApiException('validation_failed', PackageKind::OWN_MESSAGE); }
    }

    /** The package already in the workspace with this signature (any case, as MySQL compares), or null. */
    private function workspacePackage(\modX $modx, $signature) {
        $package = $modx->getObject('transport.modTransportPackage', array('signature' => $signature));
        if ($package) { return $package; }
        foreach ($modx->getCollection('transport.modTransportPackage') as $candidate) {
            if (mb_strtolower((string) $candidate->get('signature'), 'UTF-8') === mb_strtolower($signature, 'UTF-8')) { return $candidate; }
        }
        return null;
    }

    /** Resolve what to install: signature, name, version, provider, location (for a download) and what it replaces. */
    private function resolve(array $args, Context $context) {
        $modx = $context->modx;
        $hasSignature = isset($args['signature']);
        if ($hasSignature === isset($args['package_name'])) { throw new ApiException('validation_failed', 'Give exactly one of signature or package_name.'); }
        if ($hasSignature && (isset($args['provider']) || isset($args['version']))) {
            throw new ApiException('validation_failed', 'provider and version go with package_name; a signature already names one downloaded package.');
        }
        if ($hasSignature) {
            $this->refuseOwn($args['signature'], '');
            $package = $this->workspacePackage($modx, $args['signature']);
            if (!$package) { throw new ApiException('not_found', "No downloaded package '{$args['signature']}'. Find it with package_name on a provider, or see modx_list kind package."); }
            $this->refuseOwn($package->get('signature'), $package->get('package_name'));
            if (PackageKind::isInstalled($package)) { throw new ApiException('validation_failed', "Package {$package->get('signature')} is already installed."); }
            $target = array('signature' => (string) $package->get('signature'), 'package_name' => (string) $package->get('package_name'),
                'version' => PackageKind::versionOf($package), 'provider' => (int) $package->get('provider'), 'provider_name' => '', 'needs_download' => false, 'location' => '');
        } else {
            $name = $args['package_name'];
            $this->refuseOwn('', $name);
            $providerId = isset($args['provider']) ? (int) $args['provider'] : ProviderKind::defaultProviderId($modx);
            if (!$providerId) { throw new ApiException('validation_failed', 'No package provider is configured on this site.'); }
            $provider = $modx->getObject('transport.modTransportProvider', array('id' => $providerId));
            if (!$provider) { throw new ApiException('not_found', "No provider with id {$providerId}."); }
            $found = ProviderKind::search($modx, $providerId, $name, 50, 0);
            $wanted = mb_strtolower(trim($name), 'UTF-8');
            $rows = array();
            foreach ($found['results'] as $row) {
                if (!is_array($row) || mb_strtolower(trim((string) (isset($row['name']) ? $row['name'] : '')), 'UTF-8') !== $wanted) { continue; }
                $this->refuseOwn(isset($row['signature']) ? $row['signature'] : '', isset($row['name']) ? $row['name'] : '');
                if (empty($row['signature'])) { continue; }
                if (isset($args['version'])) {
                    $version = mb_strtolower(trim($args['version']), 'UTF-8');
                    $matches = array(mb_strtolower(ProviderKind::rowVersion($row), 'UTF-8'), mb_strtolower((string) (isset($row['version']) ? $row['version'] : ''), 'UTF-8'),
                        mb_strtolower((string) $row['signature'], 'UTF-8'));
                    if (!in_array($version, $matches, true)) { continue; }
                }
                $rows[] = $row;
            }
            if (!$rows) {
                throw new ApiException('not_found', "Provider '{$provider->get('name')}' has no package named '{$name}'" . (isset($args['version']) ? " in version {$args['version']}" : ' for this MODX version')
                    . '. Search it with modx_invoke kind provider op search.');
            }
            // Without an explicit version the newest stable release wins; a pre-release is installed only when asked for.
            if (!isset($args['version'])) {
                $stable = array_values(array_filter($rows, function ($row) { return !isset($row['release']) || $row['release'] === '' || $row['release'] === 'pl'; }));
                if ($stable) { $rows = $stable; }
            }
            usort($rows, function ($a, $b) {
                $order = version_compare(ProviderKind::rowVersion($b), ProviderKind::rowVersion($a));
                return $order !== 0 ? $order : strcmp((string) $b['signature'], (string) $a['signature']);
            });
            $row = $rows[0];
            $existing = $this->workspacePackage($modx, (string) $row['signature']);
            if ($existing && PackageKind::isInstalled($existing)) { throw new ApiException('validation_failed', "Package {$existing->get('signature')} is already installed."); }
            if (!$existing && empty($row['location'])) { throw new ApiException('validation_failed', 'The provider gave no download location for ' . $row['signature'] . '.'); }
            $target = array('signature' => $existing ? (string) $existing->get('signature') : (string) $row['signature'], 'package_name' => (string) $row['name'],
                'version' => ProviderKind::rowVersion($row), 'provider' => $providerId, 'provider_name' => (string) $provider->get('name'),
                'needs_download' => !$existing, 'location' => $existing ? '' : (string) $row['location']);
        }
        // An installed older (or other) version of the same package is upgraded by the install.
        $target['replaces'] = null;
        $nameKey = mb_strtolower($target['package_name'], 'UTF-8');
        foreach ($modx->getCollection('transport.modTransportPackage') as $other) {
            if (PackageKind::isInstalled($other) && mb_strtolower((string) $other->get('package_name'), 'UTF-8') === $nameKey
                && mb_strtolower((string) $other->get('signature'), 'UTF-8') !== mb_strtolower($target['signature'], 'UTF-8')) {
                $target['replaces'] = array('signature' => (string) $other->get('signature'), 'version' => PackageKind::versionOf($other));
            }
        }
        return $target;
    }

    public function run(array $args, Context $context) {
        $target = $this->resolve($args, $context);
        $confirm = !empty($args['confirm']) && empty($context->token['plan_only']);
        $plan = array('performed' => false, 'signature' => $target['signature'], 'package_name' => $target['package_name'], 'version' => $target['version'],
            'provider' => $target['provider'], 'provider_name' => $target['provider_name'], 'needs_download' => $target['needs_download'],
            'replaces' => $target['replaces'], 'reversible' => false, 'warning' => self::WARNING);
        if ($target['replaces'] !== null) {
            $plan['warning'] .= " It upgrades the installed {$target['replaces']['signature']}.";
            if (version_compare($target['version'], $target['replaces']['version']) <= 0) { $plan['warning'] .= ' The installed version is not older than this one.'; }
        }
        if (!$confirm) {
            if (!empty($context->token['plan_only'])) { $plan['planned'] = true; }
            return $plan;
        }

        $modx = $context->modx;
        $offset = PackageKind::logOffset($modx);
        $failure = null;
        $installedAt = '';
        list($result, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $target, $offset, &$failure, &$installedAt) {
            // One package operation at a time: two installs side by side would race on the same files.
            $tx->lock('package:workspace');
            $modx = $context->modx;
            $summary = 'install package ' . $target['signature'];
            try {
                $signature = $target['signature'];
                if ($target['needs_download']) {
                    $downloaded = Objects::process($modx, 'workspace/packages/rest/download', array('info' => $target['location'] . '::' . $target['signature'], 'provider' => $target['provider']));
                    if (!empty($downloaded['signature'])) { $signature = (string) $downloaded['signature']; }
                }
                $package = $this->workspacePackage($modx, $signature);
                if (!$package) { throw new ApiException('validation_failed', "The package {$signature} is not in the workspace after the download."); }
                $this->refuseOwn($package->get('signature'), $package->get('package_name'));
                Objects::process($modx, 'workspace/packages/install', array('signature' => (string) $package->get('signature')));
                $cache = $modx->getCacheManager();
                // The return value is not a failure signal (see maintenance).
                if ($cache) { $cache->refresh(); }
                $after = $this->workspacePackage($modx, (string) $package->get('signature'));
                $installedAt = $after && PackageKind::isInstalled($after) ? Kind::datetime($after->get('installed')) : '';
                return array('result' => array('signature' => (string) $package->get('signature')), 'summary' => $summary, 'informational' => true);
            } catch (\Throwable $e) {
                $failure = $e;
                return array('result' => array(), 'summary' => $summary . ' (failed)', 'informational' => true);
            }
        });
        $log = PackageKind::logSince($modx, $offset);
        if ($failure !== null) {
            if ($failure instanceof ApiException) { throw new ApiException($failure->errorCode, $failure->getMessage(), $failure->details + array('log' => $log, 'changeset_id' => $info['changeset_id']), $failure->httpStatus); }
            throw $failure;
        }
        $context->warnings[] = 'The install cannot be undone by modx_undo; remove the package with modx_uninstall_package.';
        return array('performed' => true, 'signature' => $result['signature'], 'package_name' => $target['package_name'], 'version' => $target['version'],
            'needs_download' => $target['needs_download'], 'replaces' => $target['replaces'], 'installed' => $installedAt,
            'changeset_id' => $info['changeset_id'], 'reversible' => false, 'log' => $log);
    }
}
