<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Sdk\SearchPaginationTrait;

/**
 * Stands in for an SDK `*SearchResponse`. The trait only reads getData() and
 * getHasMore(), which is the contract it documents.
 */
class FakeSearchResponse
{
    /**
     * @param list<mixed>|null $data Null models the SDK's nullable data field.
     */
    public function __construct(private readonly ?array $data, private readonly bool $hasMore)
    {
    }

    /**
     * @return list<mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    public function getHasMore(): bool
    {
        return $this->hasMore;
    }
}

/**
 * A search callable that hands back queued pages and records the offset it was
 * asked for each time. Kept as an object rather than a closure over a counter so
 * the recorded offsets can be asserted on directly.
 */
class QueuedSearch
{
    /** @var list<int> */
    public array $requestedOffsets = [];

    /** @var list<FakeSearchResponse> */
    private array $pages;

    public function __construct(FakeSearchResponse ...$pages)
    {
        $this->pages = array_values($pages);
    }

    public function __invoke(int $offset): FakeSearchResponse
    {
        $this->requestedOffsets[] = $offset;
        $page = array_shift($this->pages);

        if ($page === null) {
            throw new \LogicException('Paging asked for more pages than the test queued.');
        }

        return $page;
    }
}

/**
 * Exposes the trait's private generator so paging can be exercised directly
 * rather than inferred through a gateway.
 */
class PaginationHarness
{
    use SearchPaginationTrait;

    /**
     * Deliberately preserves keys, which is what a caller gets from a plain
     * iterator_to_array(): records must not collide across pages.
     *
     * @param callable(int): object $fetchPage
     * @return array<int, mixed>
     */
    public function collect(callable $fetchPage): array
    {
        return iterator_to_array($this->paginateSearch($fetchPage));
    }

    /**
     * @param callable(int): object $fetchPage
     * @return \Generator<int, mixed>
     */
    public function open(callable $fetchPage): \Generator
    {
        return $this->paginateSearch($fetchPage);
    }
}

class SearchPaginationTraitTest extends TestCase
{
    public function testASinglePageIsFetchedOnceAndYieldsEveryRecord(): void
    {
        $search = new QueuedSearch(new FakeSearchResponse(['a', 'b'], false));

        $records = (new PaginationHarness())->collect($search);

        $this->assertSame(['a', 'b'], $records);
        $this->assertSame([0], $search->requestedOffsets, 'A page reporting no more results must not be followed by another call.');
    }

    public function testPagingContinuesUntilTheApiReportsNoMore(): void
    {
        $search = new QueuedSearch(
            new FakeSearchResponse(['a', 'b'], true),
            new FakeSearchResponse(['c', 'd'], true),
            new FakeSearchResponse(['e'], false),
        );

        $records = (new PaginationHarness())->collect($search);

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $records);
        $this->assertCount(3, $search->requestedOffsets);
    }

    /**
     * Records must keep distinct keys across pages. Yielding a page with
     * `yield from` would restart them at zero each time, so consecutive pages
     * would collide and iterator_to_array() would keep only the last one.
     */
    public function testRecordsFromLaterPagesDoNotOverwriteEarlierOnes(): void
    {
        $search = new QueuedSearch(
            new FakeSearchResponse(['a', 'b'], true),
            new FakeSearchResponse(['c', 'd'], false),
        );

        $records = (new PaginationHarness())->collect($search);

        $this->assertCount(4, $records);
        $this->assertSame([0, 1, 2, 3], array_keys($records));
    }

    /**
     * The offset must track how many records were actually received, so the next
     * page resumes exactly where the previous one stopped.
     */
    public function testEachPageIsRequestedAtTheOffsetFollowingTheLastRecord(): void
    {
        $search = new QueuedSearch(
            new FakeSearchResponse(['a', 'b', 'c'], true),
            new FakeSearchResponse(['d', 'e'], true),
            new FakeSearchResponse(['f'], false),
        );

        (new PaginationHarness())->collect($search);

        $this->assertSame([0, 3, 5], $search->requestedOffsets);
    }

    /**
     * The API reports the limit it actually applied, which it may make smaller
     * than the one requested. Advancing by the requested size would skip the
     * records it held back, so every record must still come back.
     */
    public function testNoRecordsAreSkippedWhenTheServerAppliesASmallerLimit(): void
    {
        $everything = range(0, 249);
        $serverApplied = 50;

        $records = (new PaginationHarness())->collect(
            static function (int $offset) use ($everything, $serverApplied): FakeSearchResponse {
                $page = array_values(array_slice($everything, $offset, $serverApplied));

                return new FakeSearchResponse($page, ($offset + count($page)) < count($everything));
            },
        );

        $this->assertCount(250, $records);
        $this->assertSame($everything, array_values($records));
    }

    public function testAnEmptyFirstPageYieldsNothingAndIsFetchedOnce(): void
    {
        $search = new QueuedSearch(new FakeSearchResponse([], false));

        $records = (new PaginationHarness())->collect($search);

        $this->assertSame([], $records);
        $this->assertSame([0], $search->requestedOffsets);
    }

    /**
     * An empty page alongside hasMore leaves the offset unmoved, so the same
     * request would repeat forever. Paging has to stop instead.
     */
    public function testPagingStopsOnAnEmptyPageEvenWhenTheApiClaimsThereIsMore(): void
    {
        $search = new QueuedSearch(
            new FakeSearchResponse(['a'], true),
            new FakeSearchResponse([], true),
        );

        $records = (new PaginationHarness())->collect($search);

        $this->assertSame(['a'], $records);
        $this->assertCount(2, $search->requestedOffsets, 'Paging must stop once a page comes back empty.');
    }

    /**
     * getData() is nullable on the SDK models, so a null page must be treated as
     * an empty one rather than failing in count().
     */
    public function testANullPageIsTreatedAsEmpty(): void
    {
        $search = new QueuedSearch(new FakeSearchResponse(null, false));

        $records = (new PaginationHarness())->collect($search);

        $this->assertSame([], $records);
    }

    public function testOpeningTheGeneratorPerformsNoRequest(): void
    {
        $search = new QueuedSearch(new FakeSearchResponse(['a', 'b'], false));

        (new PaginationHarness())->open($search);

        $this->assertSame([], $search->requestedOffsets, 'Paging must stay lazy until the generator is walked.');
    }

    public function testReadingTheFirstRecordFetchesOnlyTheFirstPage(): void
    {
        $search = new QueuedSearch(
            new FakeSearchResponse(['a', 'b'], true),
            new FakeSearchResponse(['c'], false),
        );

        $generator = (new PaginationHarness())->open($search);
        $generator->current();

        $this->assertSame([0], $search->requestedOffsets, 'A caller reading one record must not pull further pages.');
    }
}
