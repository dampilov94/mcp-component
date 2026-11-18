<?php
namespace ModxMCP\Action;

use ModxMCP\ApiException;
use ModxMCP\Context;
use ModxMCP\Schema;
use ModxMCP\Verify\SiteAudit;

final class AuditSiteAction extends Action {
    public function name() { return 'audit_site'; }
    public function group() { return 'verify'; }
    public function description() {
        return 'List concrete problems of the site, each tied to an object you can fix: links [[~id]] to missing or hidden pages, calls of '
            . 'missing chunks and snippets, unused elements, static files that are gone or outside modxmcp.static_roots, duplicate page addresses, PHP that does not parse, '
            . 'pages with a missing template. Read-only. Findings are candidates to check, not orders; a count above the listed findings means more exist.';
    }
    public function inputSchema() {
        return Schema::obj(array(
            'checks' => Schema::arr(array('type' => 'string', 'enum' => SiteAudit::CHECKS), 'Checks to run; default all.', array('maxItems' => count(SiteAudit::CHECKS))),
            'limit'  => Schema::int('Findings listed per check, default 50.', array('minimum' => 1, 'maximum' => 200)),
        ));
    }
    public function outputSchema() {
        return Schema::obj(array(
            'summary'  => Schema::obj(array(), array(), true),
            'findings' => Schema::arr(Schema::obj(array(), array(), true)),
            'notes'    => Schema::arr(Schema::str()),
        ), array('summary', 'findings', 'notes'), true);
    }
    public function run(array $args, Context $context) {
        $checks = isset($args['checks']) ? array_values(array_unique($args['checks'])) : array();
        foreach ($checks as $check) {
            if (!in_array($check, SiteAudit::CHECKS, true)) { throw new ApiException('validation_failed', "Unknown check '{$check}'. Checks: " . implode(', ', SiteAudit::CHECKS) . '.'); }
        }
        return SiteAudit::run($context, $checks, isset($args['limit']) ? $args['limit'] : 50);
    }
}
