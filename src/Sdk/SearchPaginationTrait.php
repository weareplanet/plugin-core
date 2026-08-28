<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

/**
 * Pages through a paginated SDK search and yields every record it matches.
 *
 * The Portal's search endpoints answer with one page at a time, so a single call
 * is never a promise of the complete result set: callers that need all of it have
 * to keep asking. Centralised here because the paging rules are easy to get subtly
 * wrong, and getting them wrong loses records silently rather than failing.
 *
 * Applies to the `*Search` endpoints, which page by numeric offset. The plain
 * `*List` endpoints page by cursor (`after`/`before`) and carry no offset at all,
 * so they are not covered by this trait.
 */
trait SearchPaginationTrait
{
    /**
     * Yields every record of a paginated search, one page at a time.
     *
     * The caller supplies the SDK call rather than this trait making it, because
     * the search methods differ in argument order and in which of them a given
     * gateway needs. That also leaves the caller free to validate the response,
     * log, or wrap failures per page — this trait only decides when to ask again.
     *
     * Records are yielded individually rather than with `yield from`, which would
     * restart the keys at zero on every page: consecutive pages would then collide
     * on keys 0, 1, 2… and `iterator_to_array()` would keep only the final page.
     *
     * Note the SDK caps `offset` at 10000, so a result set larger than that cannot
     * be paged through to the end; the SDK itself rejects the call that would go
     * past it.
     *
     * @param callable(int): object $fetchPage Performs one search call at the given
     *        offset, returning the SDK's `*SearchResponse` for that page. It should
     *        request {@see SdkProvider::MAX_PAGE_SIZE} records, since a smaller page
     *        only costs extra round trips.
     * @return \Generator<int, mixed> Every record across every page, in API order.
     */
    private function paginateSearch(callable $fetchPage): \Generator
    {
        $offset = 0;

        do {
            $response = $fetchPage($offset);
            $page = $response->getData() ?? [];

            foreach ($page as $record) {
                yield $record;
            }

            // Advance by what the page actually held, not by the size requested:
            // the response reports the limit the server applied, which it is free
            // to make smaller than the one asked for. Stepping by the requested
            // size would then skip exactly the records the server held back.
            $offset += count($page);

            // An empty page alongside `hasMore` would otherwise spin forever,
            // re-requesting the same offset and never advancing.
        } while ($response->getHasMore() && $page !== []);
    }
}
