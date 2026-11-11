<?php
namespace ModxMCP\Action;

use ModxMCP\Context;
use ModxMCP\Kernel;
use ModxMCP\Schema;

/** One-call orientation. Scales with the site's structure, never its content: counts only. */
final class SiteInfoAction extends Action {
    public function name() { return 'site_info'; }

    public function description() {
        return 'Site overview in one call: MODX/PHP/database versions, paths, object counts, contexts and '
            . 'installed integrations.';
    }

    public function outputSchema() {
        return Schema::obj(array(
            'site_name'       => Schema::str(),
            'site_url'        => Schema::str(),
            'modx_version'    => array('type' => array('string', 'null')),
            'modxmcp_version' => Schema::str(),
            'php_version'     => Schema::str(),
            'database'        => Schema::obj(array(), array(), true),
            'paths'           => Schema::obj(array(), array(), true),
            'counts'          => Schema::obj(array(), array(), true),
            'contexts'        => Schema::arr(Schema::obj(array(), array(), true)),
            'integrations'    => Schema::arr(Schema::obj(array(), array(), true)),
        ), array('modxmcp_version', 'php_version', 'counts', 'contexts'), true);
    }

    public function run(array $args, Context $context) {
        $modx = $context->modx;
        $version = method_exists($modx, 'getVersionData') ? $modx->getVersionData() : array();
        $count = function ($class, $criteria = null) use ($modx) { return (int) $modx->getCount($class, $criteria); };

        $contexts = array();
        $byContext = array();
        $query = $modx->newQuery('modResource');
        $query->select(array('context_key', 'COUNT(*) AS total'));
        $query->where(array('deleted' => 0));
        $query->groupby('context_key');
        if ($query->prepare() && $query->stmt->execute()) {
            while ($row = $query->stmt->fetch(\PDO::FETCH_ASSOC)) { $byContext[$row['context_key']] = (int) $row['total']; }
        }
        foreach ($modx->getCollection('modContext') as $item) {
            $key = $item->get('key');
            $contexts[] = array(
                'key'       => $key,
                'name'      => (string) $item->get('name'),
                'resources' => isset($byContext[$key]) ? $byContext[$key] : 0,
            );
        }

        return array(
            'site_name'       => (string) $modx->getOption('site_name'),
            'site_url'        => (string) $modx->getOption('site_url'),
            'modx_version'    => isset($version['full_version']) ? $version['full_version'] : (isset($version['version']) ? $version['version'] : null),
            'modxmcp_version' => Kernel::VERSION,
            'php_version'     => PHP_VERSION,
            'database'        => array(
                'type'         => (string) $modx->getOption('dbtype'),
                'table_prefix' => (string) $modx->getOption('table_prefix'),
            ),
            'paths'           => array(
                'base'   => (string) $modx->getOption('base_path'),
                'core'   => (string) $modx->getOption('core_path'),
                'assets' => (string) $modx->getOption('assets_path'),
            ),
            'counts'          => array(
                'resources'           => $count('modResource', array('deleted' => 0)),
                'resources_published' => $count('modResource', array('deleted' => 0, 'published' => 1)),
                'resources_deleted'   => $count('modResource', array('deleted' => 1)),
                'templates'           => $count('modTemplate'),
                'tvs'                 => $count('modTemplateVar'),
                'chunks'              => $count('modChunk'),
                'snippets'            => $count('modSnippet'),
                'plugins'             => $count('modPlugin'),
                'categories'          => $count('modCategory'),
                'users'               => $count('modUser'),
            ),
            'contexts'        => $contexts,
            'integrations'    => $context->capabilities->integrationsReport(),
        );
    }
}
