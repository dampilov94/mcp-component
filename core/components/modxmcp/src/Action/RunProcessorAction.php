<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Kind\PackageKind;
use ModxMCP\Schema;
use ModxMCP\Write\Writer;

/**
 * Escape hatch: run any core MODX processor that the dedicated tools do not cover. It bypasses the
 * API's own safety rules, so what it may reach is limited here and the call is never journaled
 * beyond one informational changeset.
 */
final class RunProcessorAction extends WriteAction {
    const PATTERN = '/^[a-z0-9_]+(?:\/[a-z0-9_]+)*\z/';
    const WARNING = 'A processor run through modx_run_processor is not journaled and cannot be undone with modx_undo. '
        . 'Repeat with confirm: true to run it.';

    /**
     * Processors that would get around a rule of the dedicated tools. Matched as plain string prefixes of the
     * processor path (so "security" also covers "security/user/update" and anything that merely starts with it).
     * The first group is refused with the tool to use instead.
     */
    const REFUSED = array(
        'security/user'          => 'Users are handled by the kind "user" (modx_list, modx_get, modx_create, modx_update, modx_delete): it protects the service user and the credentials.',
        'security/access'        => 'Access rules are handled by the kinds access_policy, policy_template, context_access and resourcegroup_access; flush_permissions is a modx_maintenance operation.',
        'security/group'         => 'User groups are handled by the kind "user_group" (membership by the kind "user"): it protects the Administrator group.',
        'security/role'          => 'Roles are handled by the kind "role".',
        'security/resourcegroup' => 'Resource groups are handled by the kind "resource_group" and the kind "resourcegroup_access".',
        'security/documentgroup' => 'Resource groups are handled by the kind "resource_group" and the kind "resourcegroup_access".',
        'security/login'         => 'Logging in or out through the API is not possible.',
        'security/logout'        => 'Logging in or out through the API is not possible.',
        'security'               => 'Security processors are not available through modx_run_processor; users, groups, roles and access rules have their own kinds.',
        'system/settings'        => 'System settings are handled by the kind "system_setting": it hides credentials and protects the modxMCP settings.',
        'context/setting'        => 'Context settings are handled by the kind "context_setting".',
        'workspace/packages'     => 'Packages are handled by the kind "package" and the tools modx_install_package and modx_uninstall_package.',
        'workspace/providers'    => 'Package providers are handled by the kind "provider".',
        'system/config'          => 'The system configuration is not available through modx_run_processor; settings are handled by the kind "system_setting".',
        'system/import'          => 'Importing HTML files creates resources outside the journal; create resources with modx_create.',
        'browser'                => 'Files are handled by modx_list_files, modx_read_file, modx_write_file, modx_move_path and modx_delete_path.',
    );
    /** A path segment that starts like this removes or empties something: it needs confirm: true. */
    const DESTRUCTIVE = '/^(remove|delete|purge|empty|clear|flush|truncate|uninstall|reset|wipe|destroy|drop)/';

    public function name() { return 'run_processor'; }
    public function group() { return 'maintenance'; }
    public function scope() { return Scope::ADMIN; }
    public function destructive() { return true; }
    public function reversible() { return false; }

    public function unavailableReason(Context $context) {
        return $context->capabilities->settingEnabled('allow_run_processor') ? null : 'disabled_by_setting';
    }

    public function description() {
        return 'Escape hatch: run a MODX processor directly, for what the dedicated tools do not cover (disabled unless the administrator turns it on). '
            . 'Not journaled and not undoable. Without confirm: true it only checks the processor and returns the plan. '
            . 'Processors for users, access, settings, packages, files and login are refused; use the tools and kinds for those. '
            . 'A processor error comes back as success: false, not as a tool error.';
    }

    public function inputSchema() {
        return Schema::obj(array(
            'processor'       => Schema::str('Processor path, lowercase, e.g. resource/getlist or element/snippet/get.', array('minLength' => 1, 'maxLength' => 200, 'pattern' => '^[a-z0-9_]+(/[a-z0-9_]+)*$')),
            'properties'      => Schema::obj(array(), array(), true),
            'processors_path' => Schema::str('Absolute path of the processors directory of an installed add-on: core/components/<namespace>/processors/. Default: the core processors.', array('maxLength' => 500)),
            'confirm'         => Schema::bool('Actually run the processor. Without it only the plan is returned; needed for every processor whose name removes or empties something.'),
        ), array('processor'));
    }

    public function outputSchema() {
        return Schema::obj(array(
            'performed'    => Schema::bool(),
            'processor'    => Schema::str(),
            'file'         => Schema::str(),
            'success'      => Schema::bool(),
            'message'      => Schema::str(),
            'errors'       => Schema::arr(Schema::str()),
            'changeset_id' => array('type' => array('integer', 'null')),
            'reversible'   => Schema::bool(),
            'log'          => Schema::obj(array(), array(), true),
        ), array('performed', 'processor'), true);
    }

    /** The reason a processor path is refused outright, or null. */
    public static function refusal($processor) {
        foreach (self::REFUSED as $prefix => $message) {
            if (strncmp($processor, $prefix, strlen($prefix)) === 0) { return $message; }
        }
        return null;
    }

    public static function isDestructive($processor) {
        foreach (explode('/', $processor) as $segment) {
            if (preg_match(self::DESTRUCTIVE, $segment)) { return true; }
        }
        return false;
    }

    /** realpath() of a directory with a trailing separator, or null. */
    private static function directory($path) {
        if ((string) $path === '') { return null; }
        $real = @realpath((string) $path);
        return $real === false || !is_dir($real) ? null : rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
    }

    private static function inside($path, $root) {
        return $path === rtrim($root, '/\\') || strncmp($path . DIRECTORY_SEPARATOR, $root, strlen($root)) === 0;
    }

    /** The processors directory to use: the core one, or one inside the processors of an installed namespace. */
    private function root(Context $context, array $args) {
        $modx = $context->modx;
        if (!isset($args['processors_path']) || $args['processors_path'] === '') {
            $root = self::directory($modx->getOption('processors_path'));
            if ($root === null) { throw new ApiException('not_found', 'The core processors directory was not found.'); }
            return array($root, array());
        }
        $given = (string) $args['processors_path'];
        $allowed = array();
        $corePath = rtrim((string) $modx->getOption('core_path'), '/\\') . '/';
        foreach ($modx->getCollection('modNamespace') as $namespace) {
            $name = (string) $namespace->get('name');
            // modxMCP's own processors are the API's own business, not something to call from outside.
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $name) || strtolower($name) === 'modxmcp') { continue; }
            $candidate = self::directory($corePath . 'components/' . $name . '/processors');
            if ($candidate !== null) { $allowed[] = $candidate; }
        }
        $message = 'processors_path must be an absolute path inside core/components/<namespace>/processors/ of an installed namespace.';
        if (strpos($given, "\0") !== false || !preg_match('~^(/|[A-Za-z]:[\\\\/])~', $given)) { throw new ApiException('validation_failed', $message); }
        $real = @realpath($given);
        if ($real === false || !is_dir($real)) { throw new ApiException('validation_failed', $message . ' It does not exist.'); }
        foreach ($allowed as $root) {
            if (self::inside($real, $root)) { return array(rtrim($real, '/\\') . DIRECTORY_SEPARATOR, array('processors_path' => rtrim($real, '/\\') . DIRECTORY_SEPARATOR)); }
        }
        throw new ApiException('validation_failed', $message);
    }

    /** The processor file below the root, as MODX looks for it (class file first), or null. */
    private function file($root, $processor) {
        foreach (array('.class.php', '.php') as $suffix) {
            $file = $root . $processor . $suffix;
            if (!is_file($file)) { continue; }
            $real = @realpath($file);
            // A link that leaves the processors directory is not a processor of this directory.
            return $real !== false && strncmp($real, $root, strlen($root)) === 0 ? $real : null;
        }
        return null;
    }

    /** The file path relative to the site root (or the core). */
    private function relative(Context $context, $file) {
        foreach (array('base_path' => '', 'core_path' => '[core]/') as $option => $label) {
            $base = self::directory($context->modx->getOption($option));
            if ($base !== null && strncmp($file, $base, strlen($base)) === 0) { return $label . str_replace('\\', '/', substr($file, strlen($base))); }
        }
        return '[outside the site]/' . basename($file);
    }

    public function run(array $args, Context $context) {
        $processor = (string) $args['processor'];
        if (!preg_match(self::PATTERN, $processor) || strlen($processor) > 200) {
            throw new ApiException('validation_failed', 'processor must be a lowercase path such as resource/getlist (letters a-z, digits, _ and single /).');
        }
        $refusal = self::refusal($processor);
        if ($refusal !== null) { throw new ApiException('validation_failed', "The processor {$processor} is refused. {$refusal}"); }

        list($root, $options) = $this->root($context, $args);
        $file = $this->file($root, $processor);
        if ($file === null) { throw new ApiException('not_found', "No processor {$processor} in " . (isset($options['processors_path']) ? 'the given processors_path' : 'the core processors') . '.'); }

        $plan = !empty($context->token['plan_only']);
        $confirm = !empty($args['confirm']) && !$plan;
        if (self::isDestructive($processor) && empty($args['confirm'])) {
            throw new ApiException('validation_failed', "The processor {$processor} removes or empties something; it runs only with confirm: true.");
        }
        $relative = $this->relative($context, $file);
        if (!$confirm) {
            $out = array('performed' => false, 'processor' => $processor, 'file' => $relative, 'reversible' => false, 'warning' => self::WARNING);
            if ($plan) { $out['planned'] = true; }
            return $out;
        }

        $modx = $context->modx;
        $properties = isset($args['properties']) && is_array($args['properties']) ? $args['properties'] : array();
        $offset = PackageKind::logOffset($modx);
        $outcome = array('success' => false, 'message' => '', 'errors' => array(), 'object' => array(), 'total' => null);
        list(, $info) = Writer::run($context, $this->name(), function ($tx) use ($context, $processor, $properties, $options, &$outcome) {
            $tx->lock('processor:run');
            $outcome = $this->execute($context->modx, $processor, $properties, $options);
            return array('result' => array(), 'summary' => 'run_processor ' . $processor . ($outcome['success'] ? '' : ' (failed)'), 'informational' => true);
        });
        $context->warnings[] = "The processor {$processor} was run directly: it is not journaled and modx_undo cannot revert it.";
        $out = array('performed' => true, 'processor' => $processor, 'file' => $relative, 'success' => $outcome['success'], 'message' => $outcome['message'],
            'errors' => $outcome['errors'], 'object' => $this->bounded($context, $outcome['object'], $outcome),
            'changeset_id' => $info['changeset_id'], 'reversible' => false, 'log' => PackageKind::logSince($modx, $offset));
        if ($outcome['total'] !== null) { $out['total'] = $outcome['total']; }
        if (!empty($outcome['truncated'])) { $out['truncated'] = true; }
        return $out;
    }

    /** Run the processor; whatever it does, report it. Its own errors are results, not tool errors. */
    private function execute(\modX $modx, $processor, array $properties, array $options) {
        $out = array('success' => false, 'message' => '', 'errors' => array(), 'object' => array(), 'total' => null);
        $level = ob_get_level();
        ob_start();
        try {
            $response = $modx->runProcessor($processor, $properties, $options);
            if (!$response) {
                $out['message'] = 'The processor did not respond.';
                return $out;
            }
            $out['success'] = !$response->isError();
            $out['message'] = trim(strip_tags((string) $response->getMessage()));
            foreach ((method_exists($response, 'getFieldErrors') ? (array) $response->getFieldErrors() : array()) as $error) {
                $text = is_object($error) ? ((string) $error->field !== '' ? $error->field . ': ' : '') . $error->message : (string) $error;
                $out['errors'][] = trim(strip_tags($text));
            }
            $object = $response->getObject();
            // Get-list processors answer with a JSON body rather than an object.
            $body = method_exists($response, 'getResponse') ? $response->getResponse() : null;
            if (is_string($body)) { $body = json_decode($body, true); }
            if (is_array($body) && isset($body['results']) && is_array($body['results'])) {
                $object = $body['results'];
                if (isset($body['total'])) { $out['total'] = (int) $body['total']; }
            }
            $out['object'] = $object;
        } catch (\Throwable $e) {
            $out['success'] = false;
            $out['message'] = 'The processor threw ' . get_class($e) . ': ' . trim(strip_tags($e->getMessage()));
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
        return $out;
    }

    /** The object as plain JSON data (valid UTF-8), cut to half of the response limit: whole items of a list first. */
    private function bounded(Context $context, $object, array &$outcome) {
        $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE;
        $budget = max(512, (int) ($context->modx->getOption('modxmcp.max_response_bytes', null, 4194304) / 2));
        $text = json_encode($object, $flags);
        if ($text === false) { return array(); }
        $data = json_decode($text, true);
        if (strlen($text) <= $budget) { return is_array($data) ? $data : array(); }
        $outcome['truncated'] = true;
        $context->warnings[] = 'The processor object was larger than half of the response limit and was cut.';
        if (!is_array($data) || array_keys($data) !== range(0, count($data) - 1)) { return array(); }
        while ($data && strlen(json_encode($data, $flags)) > $budget) { $data = array_slice($data, 0, (int) floor(count($data) / 2)); }
        return $data;
    }
}
