<?php
namespace ModxMCP\Verify;

/** A page fetcher that can send request headers (modx_check_page uses it to render unpublished pages). */
interface HeaderPageFetcher extends PageFetcher {
    /** @param array $headers name => value */
    public function fetchWithHeaders($url, $timeoutSeconds, $maxBytes, array $headers): array;
}
