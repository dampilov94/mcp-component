<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Kind\Kind;
use ModxMCP\Kind\Objects;
use ModxMCP\Kind\PackageKind;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

final class UninstallPackageAction extends WriteAction {
    const WARNING = 'Uninstalling runs the package\'s own resolvers, which remove the files and objects it installed, and any changes '
        . 'made to those objects since. It cannot be undone with modx_undo. Repeat with confirm: true to uninstall.';

    public function name() { return 'uninstall_package'; }
    public function group() { return 'package_management'; }
    public function scope() { return Scope::ADMIN; }
    public function destructive() { return true; }
    public function reversible() { return false; }

    public function description() {
        return 'Uninstall an installed package (its resolvers remove what it installed), and with remove: true also delete the '
            . 'downloaded package file and record. Without confirm: true it only returns the plan. Irreversible: modx_undo cannot '
            . 'revert it. modxMCP itself cannot be uninstalled this way.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'signature' => Schema::str('Signature of the package, e.g. pdotools-2.13.2-pl.', array('minLength' => 1, 'maxLength' => 191)),
            'remove'    => Schema::bool('Also delete the downloaded package file and its record after uninstalling.'),
            'confirm'   => Schema::bool('Actually uninstall. Without it only the plan is returned.'),
        ), array('signature'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'performed'    => Schema::bool(),
            'signature'    => Schema::str(),
            'changeset_id' => array('type' => array('integer', 'null')),
            'reversible'   => Schema::bool(),
            'log'          => Schema::obj(array(), array(), true),
        ), array('performed', 'signature'), true);
    }

    private function refuseOwn($signature, $name) {
        if (PackageKind::isOwn($signature, $name)) { throw new ApiException('validation_failed', PackageKind::OWN_MESSAGE); }
    }

    private function find(\modX $modx, $signature) {
        $package = $modx->getObject('transport.modTransportPackage', array('signature' => $signature));
        if ($package) { return $package; }
        foreach ($modx->getCollection('transport.modTransportPackage') as $candidate) {
            if (mb_strtolower((string) $candidate->get('signature'), 'UTF-8') === mb_strtolower($signature, 'UTF-8')) { return $candidate; }
        }
        return null;
    }

    public function run(array $args, Context $context) {
        $modx = $context->modx;
        $this->refuseOwn($args['signature'], '');
        $package = $this->find($modx, $args['signature']);
        if (!$package) { throw new ApiException('not_found', "No package '{$args['signature']}' in the workspace. See modx_list kind package."); }
        $signature = (string) $package->get('signature');
        $this->refuseOwn($signature, $package->get('package_name'));
        $installed = PackageKind::isInstalled($package);
        $remove = !empty($args['remove']);
        if (!$installed && !$remove) {
            throw new ApiException('validation_failed', "Package {$signature} is not installed. Pass remove: true to delete the downloaded package.");
        }
        $confirm = !empty($args['confirm']) && empty($context->token['plan_only']);
        $plan = array('performed' => false, 'signature' => $signature, 'package_name' => (string) $package->get('package_name'),
            'version' => PackageKind::versionOf($package), 'installed' => Kind::datetime($package->get('installed')),
            'will_uninstall' => $installed, 'will_remove' => $remove, 'reversible' => false,
            'warning' => $installed ? self::WARNING : 'The downloaded package file and its record will be deleted. This cannot be undone with modx_undo.');
        if (!$confirm) {
            if (!empty($context->token['plan_only'])) { $plan['planned'] = true; }
            return $plan;
        }

        $offset = PackageKind::logOffset($modx);
        $failure = null;
        list(, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $signature, $installed, $remove, &$failure) {
            $tx->lock('package:workspace');
            $modx = $context->modx;
            $summary = 'uninstall package ' . $signature . ($remove ? ' and remove it' : '');
            try {
                if ($installed) { Objects::process($modx, 'workspace/packages/uninstall', array('signature' => $signature)); }
                if ($remove) { Objects::process($modx, 'workspace/packages/remove', array('signature' => $signature)); }
                $cache = $modx->getCacheManager();
                if ($cache) { $cache->refresh(); }
                return array('result' => array(), 'summary' => $summary, 'informational' => true);
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
        $context->warnings[] = 'The uninstall cannot be undone by modx_undo.';
        $left = $this->find($modx, $signature);
        return array('performed' => true, 'signature' => $signature, 'uninstalled' => $installed, 'removed' => $remove && !$left,
            'installed' => $left ? Kind::datetime($left->get('installed')) : '', 'changeset_id' => $info['changeset_id'], 'reversible' => false, 'log' => $log);
    }
}
