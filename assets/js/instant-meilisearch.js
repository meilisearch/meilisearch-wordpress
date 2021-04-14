// INSTANT MEILISEARCH

function wpInstantMeilisearch(searchUrl, meilisearchPublicKey, indexName, searchElt, hitsElt) {
    const search = instantsearch({
        indexName: indexName,
        searchClient: instantMeiliSearch(
            searchUrl,
            meilisearchPublicKey,
            {
                paginationTotalHits: 5,
            }
        )
    });

    search.addWidgets([
        instantsearch.widgets.searchBox({
            container: searchElt,
            placeholder: "Search",
            showReset: false,
            showSubmit: false,
        }),
        instantsearch.widgets.hits({
            container: hitsElt,
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
}
