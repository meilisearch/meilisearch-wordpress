// INSTANT MEILISEARCH

$searchUrl = $meilisearchSearchUrl === "" ? $meilisearchUrl : $meilisearchSearchUrl;
const search = instantsearch({
indexName: "wordpress",
searchClient: instantMeiliSearch(
    $searchUrl,
    $meilisearchPublicKey,
    {
    hitsPerPage: 6,
    limitPerRequest: 30
    }
)
});

search.addWidgets([
instantsearch.widgets.searchBox({
    container: "#searchbox"
}),
instantsearch.widgets.hits({
    container: "#hits",
    templates: {
    item: `
        <div>
        <div class="hit-name">
            <a href="{{url}}">
            {{#helpers.highlight}}{ "attribute": "title" }{{/helpers.highlight}}
            </a>
        </div>
        </div>
    `
    }
}),
]);

search.start();
