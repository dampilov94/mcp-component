<?php
/**
 * Manager processor: dependency-graph data for the CMP visualiser.
 *
 * Serves exactly the same graph the `dependency_graph` MCP action returns — one builder, so the
 * picture the owner sees in the manager and the map the AI reasons over can never drift apart.
 */
class ModxmcpGraphProcessor extends modProcessor {

    public function checkPermissions() {
        return $this->modx->hasPermission('settings');
    }

    public function process() {
        $modelFile = rtrim($this->modx->getOption('modxmcp.core_path', null, $this->modx->getOption('core_path') . 'components/modxmcp/'), '/\\') . '/src/autoload.php';
        if (!file_exists($modelFile)) {
            return $this->failure('modxMCP core not found.');
        }
        require_once $modelFile;

        // Checkbox values arrive as the STRINGS "true"/"false" — "false" is truthy in PHP.
        $flag = $this->getProperty('include_resources');
        $includeResources = ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true');

        // Two catches for PHP 5/7+ portability (same pattern as the model).
        try {
            $mcp = new \ModxMCP\Graph\GraphBuilder($this->modx);
            $graph = $mcp->build(array(
                'include_resources' => $includeResources,
                'max_nodes'         => 2500,
            ));
        } catch (Exception $e) {
            return $this->failure($e->getMessage());
        } catch (Throwable $e) {
            return $this->failure($e->getMessage());
        }

        return $this->success('', $graph);
    }
}
return 'ModxmcpGraphProcessor';
