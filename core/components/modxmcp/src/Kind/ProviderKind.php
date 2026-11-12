<?php
namespace ModxMCP\Kind;

use ModxMCP\Auth\Scope;
use ModxMCP\Context;
use ModxMCP\Schema;

/**
 * A package provider (modTransportProvider), e.g. modx.com or modstore.pro. Its API key is shown to admin tokens only.
 * Saving a provider makes MODX call the provider's service to verify it, so create and update need network access.
 */
final class ProviderKind extends Kind {
    public function name() { return 'provider'; }
    public function label() { return 'Package provider'; }
    public function modelClass() { return 'transport.modTransportProvider'; }
    public function processorPath() { return 'workspace/providers/'; }
    public function group() { return 'package_management'; }
    public function writeScope() { return Scope::ADMIN; }
    public function processorExtras($op) { return array(); }

    public function fields() {
        return array(
            'id'          => array('type' => 'integer', 'list' => true, 'read_only' => true),
            'name'        => array('type' => 'string', 'list' => true, 'required' => true),
            'service_url' => array('type' => 'string', 'list' => true, 'required' => true, 'description' => 'Address of the provider service; MODX verifies it when the provider is saved.'),
            'description' => array('type' => 'string'),
            'username'    => array('type' => 'string', 'list' => true),
            'api_key'     => array('type' => 'string', 'no_filter' => true, 'description' => 'Credential; shown as [hidden] unless the token has the admin scope.'),
            'priority'    => array('type' => 'integer'),
        );
    }

    public function operations() {
        return array('search' => array(
            'description' => 'Search the provider\'s catalogue (a request to the provider service). Returns name, signature, version, description, downloads and whether it is installed.',
            'read_only' => true, 'needs_object' => true,
            'input' => Schema::obj(array(
                'query' => Schema::str('Words to search for.', array('minLength' => 1, 'maxLength' => 200)),
                'limit' => Schema::int('Default 20.', array('minimum' => 1, 'maximum' => 50)),
                'start' => Schema::int('Offset, default 0.', array('minimum' => 0)),
            ), array('query')),
            'handler' => 'opSearch',
        ));
    }

    public function opSearch(Context $context, $object, array $args) {
        $providerId = (int) $object->get('id');
        $found = self::search($context->modx, $providerId, $args['query'], isset($args['limit']) ? $args['limit'] : 20, isset($args['start']) ? $args['start'] : 0);
        $installed = self::installedSignatures($context->modx);
        $items = array();
        foreach ($found['results'] as $row) {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) (isset($row['description']) ? $row['description'] : '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (mb_strlen($text, 'UTF-8') > 300) { $text = mb_substr($text, 0, 299, 'UTF-8') . '…'; }
            $signature = (string) (isset($row['signature']) ? $row['signature'] : '');
            $items[] = array(
                'name' => (string) (isset($row['name']) ? $row['name'] : ''),
                'signature' => $signature,
                'version' => self::rowVersion($row),
                'description' => $text,
                'downloads' => (int) preg_replace('/\D+/', '', (string) (isset($row['downloads']) ? $row['downloads'] : '0')),
                'installed' => isset($installed[mb_strtolower($signature, 'UTF-8')]),
            );
        }
        return array('provider' => $providerId, 'total' => $found['total'], 'items' => $items);
    }

    // ---- the API key is a credential ----

    public function secretColumns(array $state) { return array('api_key'); }

    public function redact(Context $context, $object, array $fields, array $relations) {
        if (!array_key_exists('api_key', $fields) || $fields['api_key'] === '') { return array($fields, $relations, array()); }
        if (!Scope::allows($context->token['scope'], Scope::ADMIN)) { $fields['api_key'] = Secrets::HIDDEN; }
        return array($fields, $relations, array('secret' => true));
    }

    // ---- shared with the install tool ----

    /** The provider MODX itself downloads from by default (modx.com, or modxcms.com), else the first one; 0 when there is none. */
    public static function defaultProviderId(\modX $modx) {
        foreach (array('modx.com', 'modxcms.com') as $name) {
            $provider = $modx->getObject('transport.modTransportProvider', array('name' => $name));
            if ($provider) { return (int) $provider->get('id'); }
        }
        $query = $modx->newQuery('transport.modTransportProvider');
        $query->sortby($modx->escape('id'), 'ASC');
        $query->limit(1);
        $provider = $modx->getObject('transport.modTransportProvider', $query);
        return $provider ? (int) $provider->get('id') : 0;
    }

    /**
     * Ask a provider for packages (the manager's own search). Errors of the provider come back as validation_failed.
     * @return array ['total' => int, 'results' => list of the provider's rows]
     */
    public static function search(\modX $modx, $providerId, $query, $limit, $start) {
        $response = Objects::run($modx, 'workspace/packages/rest/getlist', array('provider' => (int) $providerId, 'query' => (string) $query, 'limit' => (int) $limit, 'start' => (int) $start));
        $raw = $response->getResponse();
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
        return array(
            'total' => is_array($data) && isset($data['total']) ? (int) $data['total'] : 0,
            'results' => is_array($data) && isset($data['results']) && is_array($data['results']) ? array_values($data['results']) : array(),
        );
    }

    /** The version of a provider row as people write it: 2.13.2-pl. */
    public static function rowVersion(array $row) {
        if (!empty($row['version-compiled'])) { return (string) $row['version-compiled']; }
        $version = (string) (isset($row['version']) ? $row['version'] : '');
        return !empty($row['release']) ? $version . '-' . $row['release'] : $version;
    }

    /** Lower-cased signatures of the installed packages, as a set. */
    private static function installedSignatures(\modX $modx) {
        $out = array();
        foreach ($modx->getCollection('transport.modTransportPackage') as $package) {
            if (PackageKind::isInstalled($package)) { $out[mb_strtolower((string) $package->get('signature'), 'UTF-8')] = true; }
        }
        return $out;
    }
}
