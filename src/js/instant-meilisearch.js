// INSTANT MEILISEARCH

$searchUrl = $meilisearchSearchUrl === "" ? $meilisearchUrl : $meilisearchSearchUrl;
const search = instantsearch({
    indexName: "wordpress",
    searchClient: instantMeiliSearch(
        $searchUrl,
        $meilisearchPublicKey,
        {
            limitPerRequest: 5,
        }
    )
});

search.addWidgets([
    instantsearch.widgets.searchBox({
        container: "#searchbox",
        placeholder: "Search",
        showReset: false,
        showSubmit: false,
    }),
    instantsearch.widgets.hits({
        container: "#hits",
        templates: {
            item: `
                <a href="{{url}}">
                <div class="single-hit">
                    <div class="hit-img">
                        <img src="{{img}}" />
                    </div>
                    <div class="hit-description">
                        <div class="hit-name">
                            {{#helpers.highlight}}{ "attribute": "title" }{{/helpers.highlight}}
                        </div>
                    </div>
                </div>
                </a>
            `
        },
    }),
]);

search.start();
