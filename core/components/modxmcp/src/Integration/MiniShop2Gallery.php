<?php
namespace ModxMCP\Integration;

use ModxMCP\ApiException;
use ModxMCP\Context;

/** Reads the gallery's stored rows without opening the media source. */
final class MiniShop2Gallery {
    const DEFAULT_LIMIT = 50;
    const MAX_LIMIT = 200;

    public static function images(Context $context, $product, array $args) {
        $reason = $context->capabilities->groupBlockReason(MiniShop2::GROUP, $context->token);
        if ($reason !== null) {
            throw new ApiException('unavailable', "The gallery requires miniShop2: {$reason}.", array('reason' => $reason));
        }
        if ($product->get('class_key') !== 'msProduct') {
            throw new ApiException('validation_failed', 'Select a miniShop2 product to list its gallery.');
        }
        $modx = $context->modx;
        MiniShop2::load($modx);
        $where = array('product_id' => (int) $product->get('id'), 'type' => 'image');
        $roots = $where + array('parent' => 0);
        $total = (int) $modx->getCount('msProductFile', $roots);
        $rows = self::select($modx, 'msProductFile', array('id', 'name', 'description', 'rank', 'active', 'url'), $roots,
            $args['limit'] ?? self::DEFAULT_LIMIT, $args['offset'] ?? 0, array('rank', 'id'))->fetchAll(\PDO::FETCH_ASSOC);
        $thumbnails = array();
        if ($rows) {
            $children = self::select($modx, 'msProductFile', array('parent', 'url'), $where + array('parent:IN' => array_column($rows, 'id')), 0, 0, array('rank', 'id'));
            while ($child = $children->fetch(\PDO::FETCH_ASSOC)) {
                $parent = (int) $child['parent'];
                if (!isset($thumbnails[$parent])) {
                    $url = self::shareableUrl($child['url']);
                    if ($url !== '') { $thumbnails[$parent] = $url; }
                }
            }
        }

        $images = array();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $images[] = array('id' => $id, 'name' => (string) $row['name'], 'description' => (string) $row['description'],
                'rank' => (int) $row['rank'], 'active' => (bool) $row['active'], 'url' => self::shareableUrl($row['url']))
                + (isset($thumbnails[$id]) ? array('thumbnail' => $thumbnails[$id]) : array());
        }
        return array('total' => $total, 'images' => $images);
    }

    /** Read the class key even when the add-on's derived resource class cannot be hydrated. */
    public static function resourceClass(\modX $modx, $id) {
        try {
            return self::select($modx, 'modResource', array('class_key'), array('id' => $id), 1)->fetchColumn();
        } catch (\Throwable $e) {
            throw new ApiException('not_reversible', 'The current resource class could not be determined.');
        }
    }

    /** Physical removal of a resource cascades through the native Children composite. */
    public static function productsInTree(\modX $modx, $id) {
        $rootClass = self::resourceClass($modx, $id);
        if ($rootClass === false) { return array(); }
        $products = $rootClass === 'msProduct' ? array($id) : array();
        $seen = array($id => true);
        $parents = array($id);
        while ($parents) {
            $next = array();
            foreach (array_chunk($parents, self::MAX_LIMIT) as $batch) {
                $last = 0;
                do {
                    try {
                        $rows = self::select($modx, 'modResource', array('id', 'class_key'), array('parent:IN' => $batch, 'id:>' => $last),
                            self::MAX_LIMIT, 0, array('id'))->fetchAll(\PDO::FETCH_ASSOC);
                    } catch (\Throwable $e) {
                        throw new ApiException('not_reversible', 'Could not verify the resource subtree before its removal.');
                    }
                    foreach ($rows as $child) {
                        $key = (int) $child['id'];
                        if (isset($seen[$key])) { throw new ApiException('not_reversible', 'Could not safely verify the whole resource subtree.'); }
                        $seen[$key] = true;
                        $next[] = $key;
                        if ($child['class_key'] === 'msProduct') { $products[] = $key; }
                        $last = $key;
                    }
                } while (count($rows) === self::MAX_LIMIT);
            }
            $parents = $next;
        }
        return $products;
    }

    /** Product row removal can cascade to physical files. Check every gallery row, not just visible images. */
    public static function assertSafeRemoval(\modX $modx, array $ids) {
        try {
            MiniShop2::load($modx);
            $hasFiles = false;
            foreach (array_chunk($ids, self::MAX_LIMIT) as $batch) {
                self::select($modx, 'msProductData', array('id'), array('id:IN' => $batch), 1)->fetchColumn();
                // Native resource save may create a missing data row before restoreRelation().
                if (self::select($modx, 'msProductFile', array('id'), array('product_id:IN' => $batch), 1)->fetchColumn() !== false) {
                    $hasFiles = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            throw new ApiException('not_reversible', 'Could not verify the current product data and gallery.');
        }
        if ($hasFiles) { throw new ApiException('not_reversible', 'This undo would remove a product or its data while it has gallery files.'); }
    }

    private static function select(\modX $modx, $class, array $columns, array $where, $limit, $offset = 0, array $order = array()) {
        $query = $modx->newQuery($class);
        $query->select(array_map(array($modx, 'escape'), $columns));
        $query->where($where);
        foreach ($order as $column) { $query->sortby($modx->escape($column), 'ASC'); }
        if ($limit > 0) { $query->limit($limit, $offset); }
        if (!$query->prepare() || !$query->stmt->execute()) { throw new \RuntimeException('Could not read the stored miniShop2 state.'); }
        return $query->stmt;
    }

    /** Keep the stored URL spelling, except URLs carrying credentials or unsupported schemes. */
    private static function shareableUrl($stored) {
        $url = (string) $stored;
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return ''; }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), array('http', 'https'), true))) {
            return '';
        }
        foreach (array('query', 'fragment') as $part) {
            foreach (preg_split('/[&;]/', $parts[$part] ?? '') as $pair) {
                $key = rawurldecode(explode('=', $pair, 2)[0]);
                if (preg_match('/auth|token|secret|password|passwd|credential|signature|api[_.-]?key|access[_.-]?key|key-pair-id|^(sig|policy|key)$/i', $key)) {
                    return '';
                }
            }
        }
        return $url;
    }
}
