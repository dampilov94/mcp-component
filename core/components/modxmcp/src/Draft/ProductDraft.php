<?php
namespace ModxMCP\Draft;

use ModxMCP\ApiException;
use ModxMCP\Code\Diff;
use ModxMCP\Context;
use ModxMCP\Integration\MiniShop2;
use ModxMCP\Journal\Snapshot;
use ModxMCP\Kind\Objects;
use ModxMCP\Kind\ResourceKind;
use ModxMCP\Write\Transaction;

/** A product draft is an ordinary hidden document. Its link, rather than its properties, identifies it. */
final class ProductDraft {
    const STAGE_OP = 'stage_product_prices';
    const PRICE_FIELDS = array('price', 'old_price');
    const MAX_PRICE = 9999999999.99; // miniShop2 stores both prices as DECIMAL(12,2).
    private const PROPERTY = 'modxmcp_product_draft';

    public static function isLink($link) {
        return ($link['base_state']['fields']['class_key'] ?? null) === 'msProduct';
    }

    public static function originalData(Context $context, $original) {
        $reason = $context->capabilities->groupBlockReason(MiniShop2::GROUP, $context->token);
        if ($reason !== null) {
            throw new ApiException('unavailable', "Product drafts require miniShop2: {$reason}.", array('reason' => $reason));
        }
        if (!$original || $original->get('class_key') !== 'msProduct' || $original->get('deleted')) {
            throw new ApiException('validation_failed', 'The original must be an existing miniShop2 product outside the trash.');
        }
        MiniShop2::load($context->modx);
        $data = $context->modx->getObject('msProductData', array('id' => (int) $original->get('id')), false);
        if (!$data) { throw new ApiException('validation_failed', 'The original product has no miniShop2 data row.'); }
        return $data;
    }

    public static function assertShadow(\modX $modx, array $link, array $fields) {
        $base = $link['base_state']['fields'];
        if (($fields['class_key'] ?? '') !== 'modDocument' || !empty($fields['published']) || !empty($fields['deleted'])
            || !empty($fields['pub_date']) || !empty($fields['unpub_date']) || !empty($fields['template'])
            || empty($fields['hidemenu']) || !empty($fields['searchable']) || !empty($fields['isfolder'])
            || (int) ($fields['parent'] ?? 0) !== (int) $base['parent']
            || ($fields['context_key'] ?? '') !== $base['context_key']
            || $modx->getCount('modResource', array('parent' => $link['draft_id']))) {
            throw new ApiException('validation_failed', 'A product draft must be an unpublished, unscheduled, hidden document without a template or children.');
        }
    }

    /** Check generic update routes against the same shadow invariants used for applying a draft. */
    public static function assertUpdate(\modX $modx, $shadow, array $columns) {
        $link = (new DraftStore($modx))->ofDraft((int) $shadow->get('id'));
        if (!self::isLink($link)) { return; }
        self::assertShadow($modx, $link, $shadow->toArray('', true));
        if (array_diff(array_keys($columns), array_merge(DraftPolicy::FIELDS, array('properties')))) {
            throw new ApiException('validation_failed', 'Only editorial fields and separately staged prices can be edited on a product draft.');
        }
    }

    private static function properties($shadow) {
        $properties = $shadow->get('properties');
        if ($properties === null || $properties === '') { return array(); }
        if (!is_array($properties)) { throw new ApiException('validation_failed', 'The product draft properties could not be read.'); }
        return $properties;
    }

    private static function normalizedPrices(array $values) {
        $prices = array();
        foreach (self::PRICE_FIELDS as $field) {
            if (!array_key_exists($field, $values)) { continue; }
            $value = $values[$field];
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > self::MAX_PRICE) {
                throw new ApiException('validation_failed', "{$field} must be a finite number from 0 to " . self::MAX_PRICE . '.');
            }
            if (round((float) $value, 2) !== (float) $value) {
                throw new ApiException('validation_failed', "{$field} must have at most two decimal places.");
            }
            $prices[$field] = (float) $value;
        }
        return $prices;
    }

    public static function stagedPrices($shadow) {
        $stored = self::properties($shadow)[self::PROPERTY] ?? array();
        if (!is_array($stored)) { throw new ApiException('validation_failed', 'The staged prices could not be read.'); }
        return self::normalizedPrices($stored);
    }

    public static function planStage(ResourceKind $kind, Context $context, $shadow, array $args) {
        $id = (int) $shadow->get('id');
        $fields = $kind->objectColumns($shadow);
        if (DraftPolicy::applies($context)) {
            if (!empty($args['force'])) { throw new ApiException('forbidden_scope', 'A draft-only token cannot override a manager lock.'); }
            DraftPolicy::assertOwnDraft($context, $id, $fields);
        }
        $link = (new DraftStore($context->modx))->ofDraft($id);
        if (!self::isLink($link)) { throw new ApiException('validation_failed', 'Select the shadow draft of a miniShop2 product.'); }
        self::assertShadow($context->modx, $link, $fields);
        self::originalData($context, $kind->findByKey($context->modx, $link['original_id']));
        $prices = self::normalizedPrices($args);
        if (!$prices) { throw new ApiException('validation_failed', 'Give price and/or old_price.'); }
        return array('staged_prices' => array_replace(self::stagedPrices($shadow), $prices));
    }

    public static function stage(ResourceKind $kind, Context $context, Transaction $tx, $shadow, array $args) {
        $plan = self::planStage($kind, $context, $shadow, $args);
        $properties = self::properties($shadow);
        $properties[self::PROPERTY] = $plan['staged_prices'];
        Objects::save($context, $tx, $kind, (int) $shadow->get('id'), array('properties' => $properties), array());
        return $plan;
    }

    /** Editorial deltas use the draft's base; the values shown before applying use the current product. */
    public static function changes(ResourceKind $kind, Context $context, array $link, $original, $shadow) {
        $data = self::originalData($context, $original);
        self::assertShadow($context->modx, $link, $kind->objectColumns($shadow));
        $values = array();
        $changes = array();
        foreach (DraftPolicy::FIELDS as $field) {
            $new = $kind->value($shadow, $field);
            if ($new === (string) Snapshot::stateValue($kind, $link['base_state'], $field)) { continue; }
            $values[$field] = $new;
            $old = $kind->value($original, $field);
            if ($old !== $new) {
                $changes[$field] = $field === 'content' ? array('diff' => Diff::lines($old, $new)) : array('old' => $old, 'new' => $new);
            }
        }
        $prices = self::stagedPrices($shadow);
        $priceChanges = array();
        foreach ($prices as $field => $new) { $priceChanges[$field] = array('old' => (float) $data->get($field), 'new' => $new); }
        return array($values, $changes, $prices, $priceChanges);
    }
}
