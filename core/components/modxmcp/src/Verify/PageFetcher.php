<?php
namespace ModxMCP\Verify;

interface PageFetcher {
    public function fetch($url, $timeoutSeconds, $maxBytes): array;
}
