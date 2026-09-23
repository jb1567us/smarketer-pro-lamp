<?php

/**
 * SearchProvider Interface
 * Defines the contract for all search backends (Tavily, Exa, Google, etc.)
 */
interface SearchProvider {
    /**
     * Perform a search query.
     *
     * @param string $query The search query.
     * @param int $limit Number of results to return (default 10).
     * @return array Array of results. Each result should be:
     *               ['title' => string, 'url' => string, 'content' => string, 'score' => float|null]
     * @throws Exception If the search fails.
     */
    public function search(string $query, int $limit = 10): array;

    /**
     * Get the name of the provider.
     *
     * @return string
     */
    public function getName(): string;
}
