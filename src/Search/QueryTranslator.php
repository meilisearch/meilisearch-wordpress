<?php
/**
 * WP_Query → Meilisearch request translation.
 *
 * @package Meilisearch
 */

declare(strict_types=1);

namespace Meilisearch\WordPress\Search;

defined( 'ABSPATH' ) || exit;

use Meilisearch\WordPress\Indexing\FieldName;
use Meilisearch\WordPress\Indexing\Indexability;
use Meilisearch\WordPress\Settings\Options;

/**
 * Translates a WP_Query into a SearchRequest, or returns null ("do not intercept") for anything
 * it cannot reproduce exactly (spec § 9.2). Called twice per intercepted query: from
 * pre_get_posts (decision) and from posts_pre_query (final state, after WordPress rebuilt
 * $query->tax_query from the query vars that pre_get_posts callbacks may have changed).
 */
final class QueryTranslator {

	public const MAX_QUERY_LENGTH  = 500;
	public const MAX_HITS_PER_PAGE = 1000;

	/**
	 * Query vars that make a query untranslatable whenever they carry a value.
	 */
	private const DENIED_VARS = array(
		'p',
		'page_id',
		'name',
		'pagename',
		'title',
		'attachment',
		'attachment_id',
		'subpost',
		'subpost_id',
		'post__in',
		'post__not_in',
		'post_name__in',
		'post_parent',
		'post_parent__in',
		'post_parent__not_in',
		'post_mime_type',
		'post_password',
		'comment_count',
		'menu_order',
		'author_name',
		'offset',
		'm',
		'w',
		'year',
		'monthnum',
		'day',
		'hour',
		'minute',
		'second',
		'feed',
		'tb',
		'embed',
		'preview',
		'static',
		'error',
		'sentence',
		'exact',
		'search_columns',
	);

	/**
	 * Denied query vars that WP_Query::parse_query() fills with the integer 0 when they are not
	 * requested (absint()), so 0 means "unset" for them.
	 */
	private const ZERO_WHEN_UNSET_VARS = array( 'p', 'page_id', 'attachment_id', 'year', 'monthnum', 'day', 'w' );

	/**
	 * WP_Query orderby keys => sortable content fields.
	 */
	private const SORT_FIELDS = array(
		'date'          => 'date',
		'post_date'     => 'date',
		'modified'      => 'modified',
		'post_modified' => 'modified',
		'title'         => 'title',
		'post_title'    => 'title',
	);

	/**
	 * Supported meta_query compare operators.
	 */
	private const META_COMPARES = array( '=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT IN', 'BETWEEN', 'EXISTS', 'NOT EXISTS' );

	/**
	 * Date query columns => [field, interpreted in UTC].
	 */
	private const DATE_COLUMNS = array(
		'post_date'         => array( 'date', false ),
		'post_date_gmt'     => array( 'date', true ),
		'post_modified'     => array( 'modified', false ),
		'post_modified_gmt' => array( 'modified', true ),
	);

	/**
	 * Taxonomies stored as tax_{t}_ids on the products index (spec § 8.2).
	 */
	private const PRODUCT_TAXONOMIES = array( 'product_cat', 'product_tag' );

	/**
	 * Tax marker: always true (makes an OR group true, dropped from an AND group).
	 */
	private const ALWAYS = "\0always";

	/**
	 * Tax marker: never true (makes an AND group false, dropped from an OR group).
	 */
	private const NEVER = "\0never";

	/**
	 * Result of taxonomy_field() for a taxonomy no requested type of the index uses.
	 */
	private const NOT_APPLICABLE = "\0n/a";

	/**
	 * Constructor.
	 *
	 * @param Options                $options      Options.
	 * @param Indexability           $indexability Indexability rule (post type → logical index).
	 * @param ProductTranslator|null $products     Product translator, null when products are not indexed.
	 */
	public function __construct(
		private Options $options,
		private Indexability $indexability,
		private ?ProductTranslator $products = null
	) {}

	/**
	 * Translates a query.
	 *
	 * @param \WP_Query $query Query.
	 * @return SearchRequest|null null = do not intercept.
	 */
	public function translate( \WP_Query $query ): ?SearchRequest {
		$q = $this->query_string( $query );
		if ( null === $q || $this->has_unsupported_vars( $query ) ) {
			return null;
		}

		$types = $this->requested_types( $query );
		if ( null === $types ) {
			return null;
		}

		if ( $this->may_include_private_posts( $query, $types ) ) {
			return null;
		}

		$content_types = array();
		$has_products  = false;
		foreach ( $types as $type ) {
			$logical = $this->indexability->index_for_type( $type );
			if ( 'content' === $logical ) {
				$content_types[] = $type;
			} elseif ( 'products' === $logical ) {
				$has_products = true;
			} else {
				return null;
			}
		}

		$products = $this->products;
		if ( $has_products && null === $products ) {
			return null;
		}

		$author = $this->author_filters( $query );
		$meta   = $this->meta_filter( $query, $content_types );
		$date   = $this->date_filter( $query );
		if ( null === $author || null === $meta || null === $date ) {
			return null;
		}
		// author_id / date / meta_* are not filterable on the products index (spec § 8.3); date
		// ordering of products is ProductTranslator's sort.
		if ( $has_products && ( array() !== $author || '' !== $meta || '' !== $date ) ) {
			return null;
		}

		$logicals = array();
		$filters  = array();
		$sort     = array();
		$never    = array();

		if ( array() !== $content_types ) {
			$tax          = $this->tax_filter( $query, 'content', $content_types );
			$content_sort = $this->sort( $query );
			if ( null === $tax || null === $content_sort ) {
				return null;
			}
			$logicals[]         = 'content';
			$never['content']   = self::NEVER === $tax;
			$filters['content'] = self::non_empty( array_merge( array( FilterBuilder::in( 'post_type', $content_types ) ), $author, array( self::NEVER === $tax ? '' : $tax, $meta, $date ) ) );
			$sort['content']    = $content_sort;
		}

		if ( $has_products ) {
			$constraints = $products->constraints( $query );
			$tax         = $this->tax_filter( $query, 'products', array( 'product' ) );
			if ( null === $constraints || null === $tax ) {
				return null;
			}
			$logicals[]          = 'products';
			$never['products']   = self::NEVER === $tax;
			$filters['products'] = self::non_empty( array_merge( array( self::NEVER === $tax ? '' : $tax ), $constraints['filters'] ) );
			// Products never interpret orderby here (Task 22 owns it).
			$sort['products'] = $constraints['sort'];
		}

		// An index whose tax constraint can never match (e.g. a category clause on products) is left
		// out. When nothing is left, a content-only request that matches nothing keeps the theme's
		// normal "no results" flow; products-only falls back to MySQL.
		$logicals = array_values(
			array_filter(
				$logicals,
				static function ( string $logical ) use ( $never ): bool {
					return ! $never[ $logical ];
				}
			)
		);
		if ( array() === $logicals ) {
			if ( ! isset( $filters['content'] ) ) {
				return null;
			}
			$logicals = array( 'content' );
			$filters  = array( 'content' => array( FilterBuilder::in( 'post_type', array() ) ) );
			$sort     = array( 'content' => $sort['content'] );
		}
		$filters = array_intersect_key( $filters, array_flip( $logicals ) );
		$sort    = array_intersect_key( $sort, array_flip( $logicals ) );

		// Federated queries must share the same sort rules (spec § 9.4): the engine rejects
		// conflicting ones (invalid_multi_search_query_ranking_rules).
		if ( count( $logicals ) > 1 && $sort['content'] !== $sort['products'] ) {
			return null;
		}

		// An index that no full reindex has filled yet would answer with zero results: MySQL answers.
		foreach ( $logicals as $logical ) {
			if ( ! $this->options->is_populated( $logical ) ) {
				return null;
			}
		}

		list( $page, $hits_per_page ) = $this->pagination( $query );

		$search = $this->options->search();
		$hybrid = null;
		if ( '' !== $search['embedder'] && $search['semantic_ratio'] > 0 ) {
			$hybrid = array(
				'embedder'      => $search['embedder'],
				'semanticRatio' => min( 1.0, $search['semantic_ratio'] ),
			);
		}

		return new SearchRequest( $logicals, $q, $page, $hits_per_page, $filters, $sort, $hybrid, $search['highlight'] );
	}

	/**
	 * Trimmed, length-capped `s`, or null when missing, blank or not valid UTF-8.
	 *
	 * @param \WP_Query $query Query.
	 * @return string|null
	 */
	private function query_string( \WP_Query $query ): ?string {
		$raw = $query->get( 's' );
		if ( ! is_string( $raw ) || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			return null;
		}
		$trimmed = preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw );
		if ( null === $trimmed || '' === $trimmed ) {
			return null;
		}
		return mb_substr( $trimmed, 0, self::MAX_QUERY_LENGTH );
	}

	/**
	 * Whether any query var outside the translatable set carries a value.
	 *
	 * @param \WP_Query $query Query.
	 * @return bool
	 */
	private function has_unsupported_vars( \WP_Query $query ): bool {
		foreach ( self::DENIED_VARS as $var ) {
			$value = $query->get( $var );
			if ( in_array( $var, self::ZERO_WHEN_UNSET_VARS, true ) && ( 0 === $value || '0' === $value ) ) {
				continue;
			}
			if ( self::has_value( $value ) ) {
				return true;
			}
		}
		$status = $query->get( 'post_status' );
		if ( null !== $status && ! in_array( $status, array( '', 'publish', array( 'publish' ) ), true ) ) {
			return true;
		}
		if ( ! in_array( $query->get( 'fields' ), array( '', 'all', null ), true ) ) {
			return true;
		}
		// has_password=false (only posts without a password) is what the index holds anyway.
		return (bool) $query->get( 'suppress_filters' ) || (bool) $query->get( 'has_password' );
	}

	/**
	 * Whether WP_Query would add private posts to this query for the current user. With an empty
	 * post_status, WordPress includes the private status for users holding the type's
	 * read_private_posts capability; the index only holds public posts.
	 *
	 * @param \WP_Query $query Query.
	 * @param string[]  $types Requested post types.
	 * @return bool
	 */
	private function may_include_private_posts( \WP_Query $query, array $types ): bool {
		$status = $query->get( 'post_status' );
		if ( null !== $status && '' !== $status && array() !== $status ) {
			return false;
			// Explicit statuses were already validated by has_unsupported_vars().
		}
		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			$cap    = is_object( $object ) && isset( $object->cap->read_private_posts ) && is_string( $object->cap->read_private_posts ) && '' !== $object->cap->read_private_posts
				? $object->cap->read_private_posts
				: 'read_private_posts';
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Requested post types, or null when they cannot be determined.
	 *
	 * @param \WP_Query $query Query.
	 * @return string[]|null
	 */
	private function requested_types( \WP_Query $query ): ?array {
		$raw = $query->get( 'post_type' );
		if ( null === $raw || '' === $raw || array() === $raw ) {
			if ( ! $query->is_search() ) {
				return array( 'post' );
			}
			$raw = 'any';
		}
		if ( 'any' === $raw ) {
			$types = array();
			foreach ( get_post_types( array( 'exclude_from_search' => false ) ) as $type ) {
				// WordPress lists attachments in "any" searches but only returns public statuses,
				// and attachments are stored as 'inherit': an unindexed attachment type adds nothing.
				if ( 'attachment' === $type && null === $this->indexability->index_for_type( 'attachment' ) ) {
					continue;
				}
				$types[] = (string) $type;
			}
			return array() === $types ? null : $types;
		}
		if ( is_string( $raw ) ) {
			return array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$types = array();
		foreach ( $raw as $type ) {
			if ( ! is_string( $type ) || '' === $type || 'any' === $type ) {
				return null;
			}
			$types[] = $type;
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Page and hits per page, mirroring WP_Query::get_posts().
	 *
	 * @param \WP_Query $query Query.
	 * @return array{0: int, 1: int}
	 */
	private function pagination( \WP_Query $query ): array {
		if ( (bool) $query->get( 'nopaging' ) ) {
			return array( 1, self::MAX_HITS_PER_PAGE );
		}
		$per_page = (int) $query->get( 'posts_per_page' );
		$show     = (int) $query->get( 'showposts' );
		if ( $show > 0 ) {
			$per_page = $show;
		}
		$archive = (int) $query->get( 'posts_per_archive_page' );
		if ( 0 !== $archive && $query->is_search() ) {
			$per_page = $archive;
		}
		if ( 0 === $per_page ) {
			$per_page = (int) get_option( 'posts_per_page' );
		}
		if ( -1 === $per_page ) {
			return array( 1, self::MAX_HITS_PER_PAGE );
		}
		$per_page = max( 1, abs( $per_page ) );
		return array( max( 1, (int) $query->get( 'paged' ) ), min( $per_page, self::MAX_HITS_PER_PAGE ) );
	}

	/**
	 * Sort rules for the content index; [] = relevance; null = unsupported orderby.
	 *
	 * @param \WP_Query $query Query.
	 * @return string[]|null
	 */
	private function sort( \WP_Query $query ): ?array {
		$orderby = $query->get( 'orderby' );
		$order   = self::direction( $query->get( 'order' ) );
		$keys    = array();
		if ( null === $orderby ) {
			$orderby = '';
		}
		if ( is_array( $orderby ) ) {
			foreach ( $orderby as $key => $direction ) {
				if ( is_int( $key ) ) {
					$keys[ (string) $direction ] = $order;
				} else {
					$keys[ $key ] = self::direction( $direction );
				}
			}
		} elseif ( is_string( $orderby ) ) {
			$parts = preg_split( '/[\s,]+/', trim( $orderby ), -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $parts ) {
				return null;
			}
			foreach ( $parts as $key ) {
				$keys[ $key ] = $order;
			}
		} else {
			return null;
		}

		if ( isset( $keys['relevance'] ) || isset( $keys['none'] ) ) {
			return 1 === count( $keys ) ? array() : null;
		}

		$rules = array();
		$seen  = array();
		foreach ( $keys as $key => $direction ) {
			$field = self::SORT_FIELDS[ $key ] ?? null;
			if ( null === $field ) {
				return null;
			}
			if ( isset( $seen[ $field ] ) ) {
				continue;
			}
			$seen[ $field ] = true;
			$rules[]        = $field . ':' . $direction;
		}
		return $rules;
	}

	/**
	 * Author filters from author, author__in and author__not_in (merged like WP_Query does).
	 *
	 * @param \WP_Query $query Query.
	 * @return string[]|null
	 */
	private function author_filters( \WP_Query $query ): ?array {
		$in     = array();
		$not_in = array();
		$author = $query->get( 'author' );
		if ( self::has_value( $author ) && '0' !== (string) $author ) {
			if ( ! is_int( $author ) && ! is_string( $author ) ) {
				return null;
			}
			$parts = preg_split( '/[,\s]+/', (string) $author, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( false === $parts ? array() : $parts as $part ) {
				if ( ! is_numeric( $part ) ) {
					return null;
				}
				$id = (int) $part;
				if ( $id > 0 ) {
					$in[] = $id;
				} elseif ( $id < 0 ) {
					$not_in[] = -$id;
				}
			}
		}

		$extra_in     = self::id_list( $query->get( 'author__in' ) );
		$extra_not_in = self::id_list( $query->get( 'author__not_in' ) );
		if ( null === $extra_in || null === $extra_not_in ) {
			return null;
		}
		$in     = array_values( array_unique( array_merge( $in, $extra_in ) ) );
		$not_in = array_values( array_unique( array_merge( $not_in, $extra_not_in ) ) );

		$filters = array();
		if ( array() !== $in ) {
			$filters[] = FilterBuilder::in( 'author_id', $in );
		}
		if ( array() !== $not_in ) {
			$filters[] = FilterBuilder::not_in( 'author_id', $not_in );
		}
		return $filters;
	}

	/**
	 * Tax filter for one logical index from $query->tax_query (which already contains cat, tag,
	 * category_name, custom taxonomy query vars and tax_query). '' = no constraint, NEVER = no
	 * document of this index can match, null = untranslatable.
	 *
	 * @param \WP_Query $query   Query.
	 * @param string    $logical 'content' | 'products'.
	 * @param string[]  $types   Requested post types stored in that index.
	 * @return string|null
	 */
	private function tax_filter( \WP_Query $query, string $logical, array $types ): ?string {
		$tax_query = $query->tax_query;
		if ( ! $tax_query instanceof \WP_Tax_Query || array() === $tax_query->queries ) {
			return '';
		}
		$relation   = 'OR' === strtoupper( (string) $tax_query->relation ) ? 'OR' : 'AND';
		$expression = $this->tax_group( $tax_query->queries, $relation, $logical, $types, 0 );
		if ( null === $expression ) {
			return null;
		}
		return self::ALWAYS === $expression ? '' : $expression;
	}

	/**
	 * One level of a tax query (clauses and nested groups).
	 *
	 * @param array<int|string, mixed> $queries  Clauses.
	 * @param string                   $relation AND | OR.
	 * @param string                   $logical  'content' | 'products'.
	 * @param string[]                 $types    Requested post types stored in that index.
	 * @param int                      $depth    0 = top level.
	 * @return string|null
	 */
	private function tax_group( array $queries, string $relation, string $logical, array $types, int $depth ): ?string {
		$parts = array();
		foreach ( $queries as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}
			if ( self::is_first_order_tax_clause( $clause ) ) {
				$part = $this->tax_clause( $clause, $logical, $types, 0 === $depth && 'AND' === $relation );
			} else {
				$nested = isset( $clause['relation'] ) && is_string( $clause['relation'] ) && 'OR' === strtoupper( $clause['relation'] ) ? 'OR' : 'AND';
				$part   = $this->tax_group( $clause, $nested, $logical, $types, $depth + 1 );
			}
			if ( null === $part ) {
				return null;
			}
			$parts[] = $part;
		}
		return self::combine( $parts, $relation );
	}

	/**
	 * One first-order tax clause, with WP_Tax_Query semantics, for one logical index.
	 *
	 * @param array<string, mixed> $clause    Clause.
	 * @param string               $logical   'content' | 'products'.
	 * @param string[]             $types     Requested post types stored in that index.
	 * @param bool                 $top_level Whether the clause is ANDed at the top level.
	 * @return string|null
	 */
	private function tax_clause( array $clause, string $logical, array $types, bool $top_level ): ?string {
		$taxonomy = isset( $clause['taxonomy'] ) && is_string( $clause['taxonomy'] ) ? $clause['taxonomy'] : '';
		$operator = isset( $clause['operator'] ) && is_string( $clause['operator'] ) ? strtoupper( $clause['operator'] ) : 'IN';
		if ( '' === $taxonomy ) {
			return null;
		}
		if ( 'products' === $logical && ( 'product_visibility' === $taxonomy || str_starts_with( $taxonomy, 'pa_' ) ) ) {
			// Translated by ProductTranslator (Task 22), which only supports them ANDed at the top level.
			return $top_level ? self::ALWAYS : null;
		}

		$field = $this->taxonomy_field( $taxonomy, $logical, $types );
		if ( null === $field ) {
			return null;
		}

		$terms = array();
		foreach ( (array) ( $clause['terms'] ?? array() ) as $term ) {
			if ( null !== $term && '' !== $term && 0 !== $term && '0' !== $term ) {
				$terms[] = $term;
			}
		}
		$terms = array_values( array_unique( $terms, SORT_REGULAR ) );

		if ( self::NOT_APPLICABLE === $field ) {
			// No requested post type of this index uses the taxonomy: exclusions always hold,
			// inclusions never do (e.g. WooCommerce's product_visibility NOT IN on posts).
			switch ( $operator ) {
				case 'NOT IN':
				case 'NOT EXISTS':
					return self::ALWAYS;
				case 'AND':
					return array() === $terms ? '' : self::NEVER;
				case 'IN':
				case 'EXISTS':
					return self::NEVER;
				default:
					return null;
			}
		}

		switch ( $operator ) {
			case 'IN':
			case 'NOT IN':
			case 'AND':
				$children = ! array_key_exists( 'include_children', $clause ) || (bool) $clause['include_children'];
				// tax_*_ids hold each term plus its ancestors, which is exactly include_children = true for
				// IN / NOT IN. Exact-term matching and WordPress's AND-with-children cannot be expressed.
				if ( is_taxonomy_hierarchical( $taxonomy ) && ( ! $children || 'AND' === $operator ) ) {
					return null;
				}
				if ( array() === $terms ) {
					return 'IN' === $operator ? FilterBuilder::in( $field, array() ) : '';
				}
				$ids = $this->term_ids( $taxonomy, $clause['field'] ?? 'term_id', $terms );
				if ( null === $ids ) {
					return null;
				}
				if ( 'IN' === $operator ) {
					return FilterBuilder::in( $field, $ids );
					// Unknown terms → IN [] → no results, like WordPress.
				}
				if ( 'NOT IN' === $operator ) {
					return array() === $ids ? '' : FilterBuilder::not_in( $field, $ids );
				}
				if ( count( $ids ) < count( $terms ) ) {
					return FilterBuilder::in( $field, array() );
					// WordPress: "Inexistent terms" → no results.
				}
				$parts = array();
				foreach ( $ids as $id ) {
					$parts[] = FilterBuilder::compare( $field, '=', $id );
				}
				return FilterBuilder::all( $parts );
			case 'EXISTS':
				return FilterBuilder::all( array( FilterBuilder::exists( $field ), FilterBuilder::negate( FilterBuilder::is_empty( $field ) ) ) );
			case 'NOT EXISTS':
				return FilterBuilder::any( array( FilterBuilder::not_exists( $field ), FilterBuilder::is_empty( $field ) ) );
			default:
				return null;
		}//end switch
	}

	/**
	 * The tax_{taxonomy}_ids field of a logical index; NOT_APPLICABLE when no requested type of
	 * that index uses the taxonomy; null when a type uses it but it is not indexed.
	 *
	 * @param string   $taxonomy Taxonomy.
	 * @param string   $logical  'content' | 'products'.
	 * @param string[] $types    Requested post types stored in that index.
	 * @return string|null
	 */
	private function taxonomy_field( string $taxonomy, string $logical, array $types ): ?string {
		$normalized = FieldName::normalize( $taxonomy );
		if ( null === $normalized ) {
			return null;
		}
		$field = 'tax_' . $normalized . '_ids';
		if ( 'products' === $logical ) {
			if ( in_array( $taxonomy, self::PRODUCT_TAXONOMIES, true ) ) {
				return $field;
			}
			return is_object_in_taxonomy( 'product', $taxonomy ) ? null : self::NOT_APPLICABLE;
		}
		$indexed_somewhere = false;
		$used_somewhere    = false;
		foreach ( $types as $type ) {
			$indexed = in_array( $taxonomy, $this->options->taxonomies_for( $type ), true );
			$used    = is_object_in_taxonomy( $type, $taxonomy );
			if ( $used && ! $indexed ) {
				return null;
			}
			$indexed_somewhere = $indexed_somewhere || $indexed;
			$used_somewhere    = $used_somewhere || $used;
		}
		if ( $indexed_somewhere ) {
			return $field;
		}
		return $used_somewhere ? null : self::NOT_APPLICABLE;
	}

	/**
	 * Resolves clause terms to term IDs; unknown terms are skipped.
	 *
	 * @param string       $taxonomy Taxonomy.
	 * @param mixed        $field    term_id | slug | name | term_taxonomy_id.
	 * @param array<mixed> $terms    Terms.
	 * @return int[]|null
	 */
	private function term_ids( string $taxonomy, mixed $field, array $terms ): ?array {
		$field = is_string( $field ) ? $field : 'term_id';
		$ids   = array();
		if ( in_array( $field, array( 'term_id', 'id', 'ID' ), true ) ) {
			foreach ( $terms as $term ) {
				if ( ! is_numeric( $term ) ) {
					return null;
				}
				$id = abs( (int) $term );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			return array_values( array_unique( $ids ) );
		}
		if ( ! in_array( $field, array( 'slug', 'name', 'term_taxonomy_id' ), true ) ) {
			return null;
		}
		foreach ( $terms as $term ) {
			if ( ! is_scalar( $term ) ) {
				return null;
			}
			$found = get_term_by( $field, (string) $term, $taxonomy, 'ARRAY_A' );
			if ( is_array( $found ) && isset( $found['term_id'] ) ) {
				$ids[] = (int) $found['term_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Meta filter from meta_query and the meta_key / meta_value shorthand. '' = no constraint.
	 *
	 * @param \WP_Query $query         Query.
	 * @param string[]  $content_types Requested content post types.
	 * @return string|null
	 */
	private function meta_filter( \WP_Query $query, array $content_types ): ?string {
		$clauses = $this->meta_clauses( $query );
		if ( array() === $clauses ) {
			return '';
		}
		if ( array() === $content_types ) {
			return null;
		}
		return $this->meta_group( $clauses, $content_types );
	}

	/**
	 * Mirrors WP_Meta_Query::parse_query_vars().
	 *
	 * @param \WP_Query $query Query.
	 * @return array<int|string, mixed>
	 */
	private function meta_clauses( \WP_Query $query ): array {
		$primary = array();
		foreach ( array( 'key', 'compare', 'type', 'compare_key', 'type_key' ) as $part ) {
			$value = $query->get( 'meta_' . $part );
			if ( ! empty( $value ) ) {
				$primary[ $part ] = $value;
			}
		}
		$value = $query->get( 'meta_value' );
		if ( null !== $value && '' !== $value && array() !== $value ) {
			$primary['value'] = $value;
		}
		$existing = $query->get( 'meta_query' );
		$existing = is_array( $existing ) ? $existing : array();
		if ( array() !== $primary && array() !== $existing ) {
			return array(
				'relation' => 'AND',
				$primary,
				$existing,
			);
		}
		if ( array() !== $primary ) {
			return array( $primary );
		}
		return $existing;
	}

	/**
	 * One level of a meta query.
	 *
	 * @param array<int|string, mixed> $group         Clauses.
	 * @param string[]                 $content_types Requested content post types.
	 * @return string|null
	 */
	private function meta_group( array $group, array $content_types ): ?string {
		$relation = isset( $group['relation'] ) && is_string( $group['relation'] ) && 'OR' === strtoupper( $group['relation'] ) ? 'OR' : 'AND';
		$parts    = array();
		foreach ( $group as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}
			if ( isset( $clause['key'] ) || isset( $clause['value'] ) ) {
				$part = $this->meta_clause( $clause, $content_types );
			} else {
				$part = $this->meta_group( $clause, $content_types );
			}
			if ( null === $part ) {
				return null;
			}
			$parts[] = $part;
		}
		return 'OR' === $relation ? FilterBuilder::any( $parts ) : FilterBuilder::all( $parts );
	}

	/**
	 * One first-order meta clause, with WP_Meta_Query semantics.
	 *
	 * @param array<string, mixed> $clause        Clause.
	 * @param string[]             $content_types Requested content post types.
	 * @return string|null
	 */
	private function meta_clause( array $clause, array $content_types ): ?string {
		if ( ! isset( $clause['key'] ) || ! is_string( $clause['key'] ) || '' === $clause['key'] ) {
			return null;
			// Value-only clauses and key arrays are not supported.
		}
		if ( isset( $clause['compare_key'] ) && ( ! is_string( $clause['compare_key'] ) || '=' !== strtoupper( $clause['compare_key'] ) ) ) {
			return null;
		}
		if ( ! empty( $clause['type_key'] ) ) {
			return null;
		}
		$key = $clause['key'];
		foreach ( $content_types as $type ) {
			if ( ! in_array( $key, $this->options->meta_keys_for( $type ), true ) ) {
				return null;
				// Every requested type must have the key indexed, or its documents lack the field.
			}
		}
		$normalized = FieldName::normalize( $key );
		if ( null === $normalized ) {
			return null;
		}
		$field = 'meta_' . $normalized;

		$type    = isset( $clause['type'] ) && is_string( $clause['type'] ) ? strtoupper( trim( $clause['type'] ) ) : '';
		$numeric = 'NUMERIC' === $type || 1 === preg_match( '/^DECIMAL(\(\d+,\s*\d+\))?$/', $type );
		if ( ! $numeric && '' !== $type && 'CHAR' !== $type ) {
			return null;
		}

		$has_value = array_key_exists( 'value', $clause ) && array() !== $clause['value'];
		if ( isset( $clause['compare'] ) && is_string( $clause['compare'] ) ) {
			$compare = strtoupper( $clause['compare'] );
		} elseif ( $has_value && is_array( $clause['value'] ) ) {
			$compare = 'IN';
		} else {
			$compare = '=';
		}
		if ( ! in_array( $compare, self::META_COMPARES, true ) ) {
			return null;
		}
		if ( 'EXISTS' === $compare ) {
			return FilterBuilder::exists( $field );
		}
		if ( 'NOT EXISTS' === $compare ) {
			return FilterBuilder::not_exists( $field );
		}
		if ( ! $has_value ) {
			return '=' === $compare ? FilterBuilder::exists( $field ) : null;
			// Key-only clause: "has this key".
		}

		$raw = $clause['value'];
		switch ( $compare ) {
			case 'IN':
			case 'NOT IN':
				$values = $numeric ? self::numbers( self::value_list( $raw ) ) : self::scalars( self::value_list( $raw ) );
				if ( null === $values || array() === $values ) {
					return null;
				}
				if ( 'IN' === $compare ) {
					return FilterBuilder::in( $field, $values );
				}
				// WordPress requires the key to exist for NOT IN / !=.
				return FilterBuilder::all( array( FilterBuilder::exists( $field ), FilterBuilder::not_in( $field, $values ) ) );
			case 'BETWEEN':
				$values = self::numbers( self::value_list( $raw ) );
				if ( null === $values || count( $values ) < 2 ) {
					return null;
				}
				return FilterBuilder::between( $field, $values[0], $values[1] );
			case '=':
			case '!=':
				$values = $numeric ? self::numbers( array( $raw ) ) : self::scalars( array( $raw ) );
				if ( null === $values ) {
					return null;
				}
				$expression = FilterBuilder::compare( $field, $compare, $values[0] );
				return '!=' === $compare ? FilterBuilder::all( array( FilterBuilder::exists( $field ), $expression ) ) : $expression;
			default:
				$values = self::numbers( array( $raw ) );
				// Range comparisons need numbers.
				if ( null === $values ) {
					return null;
				}
				return FilterBuilder::compare( $field, $compare, $values[0] );
		}//end switch
	}

	/**
	 * Date filter from date_query (after / before / inclusive / column only). '' = no constraint.
	 *
	 * @param \WP_Query $query Query.
	 * @return string|null
	 */
	private function date_filter( \WP_Query $query ): ?string {
		$date_query = $query->get( 'date_query' );
		if ( ! self::has_value( $date_query ) ) {
			return '';
		}
		if ( ! is_array( $date_query ) ) {
			return null;
		}
		if ( ! isset( $date_query[0] ) ) {
			$date_query = array( $date_query );
			// WP_Date_Query accepts a bare clause.
		}
		$relation = isset( $date_query['relation'] ) && is_string( $date_query['relation'] ) && 'OR' === strtoupper( $date_query['relation'] ) ? 'OR' : 'AND';
		$column   = isset( $date_query['column'] ) && is_string( $date_query['column'] ) ? $date_query['column'] : 'post_date';
		$parts    = array();
		foreach ( $date_query as $key => $clause ) {
			if ( 'relation' === $key || 'column' === $key ) {
				continue;
			}
			if ( ! is_int( $key ) || ! is_array( $clause ) ) {
				return null;
			}
			$part = $this->date_clause( $clause, $column );
			if ( null === $part ) {
				return null;
			}
			$parts[] = $part;
		}
		return 'OR' === $relation ? FilterBuilder::any( $parts ) : FilterBuilder::all( $parts );
	}

	/**
	 * One date_query clause with only after / before / inclusive / column.
	 *
	 * @param array<string, mixed> $clause         Clause.
	 * @param string               $default_column Column from the top level.
	 * @return string|null
	 */
	private function date_clause( array $clause, string $default_column ): ?string {
		$column    = $default_column;
		$after     = null;
		$before    = null;
		$inclusive = false;
		foreach ( $clause as $key => $value ) {
			if ( 'after' === $key ) {
				$after = $value;
			} elseif ( 'before' === $key ) {
				$before = $value;
			} elseif ( 'inclusive' === $key ) {
				$inclusive = (bool) $value;
			} elseif ( 'column' === $key && is_string( $value ) ) {
				$column = $value;
			} else {
				return null;
			}
		}
		$column_info = self::DATE_COLUMNS[ $column ] ?? null;
		if ( null === $column_info || ( empty( $after ) && empty( $before ) ) ) {
			return null;
		}
		list( $field, $utc ) = $column_info;
		$timezone            = $utc ? new \DateTimeZone( 'UTC' ) : wp_timezone();
		$parts               = array();
		if ( ! empty( $after ) ) {
			$timestamp = self::timestamp( $after, ! $inclusive, $timezone );
			if ( null === $timestamp ) {
				return null;
			}
			$parts[] = FilterBuilder::compare( $field, $inclusive ? '>=' : '>', $timestamp );
		}
		if ( ! empty( $before ) ) {
			$timestamp = self::timestamp( $before, $inclusive, $timezone );
			if ( null === $timestamp ) {
				return null;
			}
			$parts[] = FilterBuilder::compare( $field, $inclusive ? '<=' : '<', $timestamp );
		}
		return FilterBuilder::all( $parts );
	}

	/**
	 * Unix timestamp for a date_query bound, mirroring WP_Date_Query::build_mysql_datetime().
	 *
	 * @param mixed         $value          String or array{year, month?, day?, hour?, minute?, second?}.
	 * @param bool          $default_to_max Fill missing parts with their maximum.
	 * @param \DateTimeZone $timezone       Timezone the value is expressed in.
	 * @return int|null
	 */
	private static function timestamp( mixed $value, bool $default_to_max, \DateTimeZone $timezone ): ?int {
		if ( is_string( $value ) ) {
			$matched = self::date_parts( $value );
			if ( null === $matched ) {
				try {
					return ( new \DateTimeImmutable( $value, $timezone ) )->getTimestamp();
				} catch ( \Exception $e ) {
					return null;
				}
			}
			$value = $matched;
		}
		if ( ! is_array( $value ) || ! isset( $value['year'] ) ) {
			return null;
		}
		$parts = array();
		foreach ( $value as $key => $part ) {
			if ( ! in_array( $key, array( 'year', 'month', 'day', 'hour', 'minute', 'second' ), true ) || ! is_numeric( $part ) ) {
				return null;
			}
			$parts[ $key ] = abs( (int) $part );
		}
		$defaults = $default_to_max
			? array(
				'month'  => 12,
				'hour'   => 23,
				'minute' => 59,
				'second' => 59,
			)
			: array(
				'month'  => 1,
				'day'    => 1,
				'hour'   => 0,
				'minute' => 0,
				'second' => 0,
			);
		$parts    = array_merge( $defaults, $parts );
		try {
			if ( ! isset( $parts['day'] ) ) {
				$parts['day'] = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $parts['year'], $parts['month'] ), $timezone ) )->format( 't' );
			}
			$local = sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $parts['year'], $parts['month'], $parts['day'], $parts['hour'], $parts['minute'], $parts['second'] );
			return ( new \DateTimeImmutable( $local, $timezone ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Parses the partial formats WP_Date_Query recognizes (Y, Y-m, Y-m-d, Y-m-d H:i).
	 *
	 * @param string $value Date string.
	 * @return array<string, int>|null
	 */
	private static function date_parts( string $value ): ?array {
		if ( 1 !== preg_match( '/^(\d{4})(?:-(\d{2})(?:-(\d{2})(?: (\d{2}):(\d{2}))?)?)?$/', $value, $m ) ) {
			return null;
		}
		$names = array(
			1 => 'year',
			2 => 'month',
			3 => 'day',
			4 => 'hour',
			5 => 'minute',
		);
		$parts = array();
		foreach ( $names as $index => $name ) {
			if ( isset( $m[ $index ] ) && '' !== $m[ $index ] ) {
				$parts[ $name ] = (int) $m[ $index ];
			}
		}
		return $parts;
	}

	/**
	 * Combines tax parts, honouring the ALWAYS / NEVER markers.
	 *
	 * @param string[] $parts    Parts.
	 * @param string   $relation AND | OR.
	 * @return string
	 */
	private static function combine( array $parts, string $relation ): string {
		$has_always = in_array( self::ALWAYS, $parts, true );
		$has_never  = in_array( self::NEVER, $parts, true );
		$rest       = self::non_empty(
			array_filter(
				$parts,
				static function ( string $part ): bool {
					return self::ALWAYS !== $part && self::NEVER !== $part;
				}
			)
		);
		if ( 'OR' === $relation ) {
			if ( $has_always ) {
				return self::ALWAYS;
			}
			return ( array() === $rest && $has_never ) ? self::NEVER : FilterBuilder::any( $rest );
		}
		if ( $has_never ) {
			return self::NEVER;
		}
		return ( array() === $rest && $has_always ) ? self::ALWAYS : FilterBuilder::all( $rest );
	}

	/**
	 * Drops '' parts and reindexes.
	 *
	 * @param array<int|string, string> $parts Parts.
	 * @return string[]
	 */
	private static function non_empty( array $parts ): array {
		return array_values(
			array_filter(
				$parts,
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);
	}

	/**
	 * WP_Tax_Query::is_first_order_clause().
	 *
	 * @param array<int|string, mixed> $clause Clause.
	 * @return bool
	 */
	private static function is_first_order_tax_clause( array $clause ): bool {
		return array() === $clause
			|| array_key_exists( 'terms', $clause )
			|| array_key_exists( 'taxonomy', $clause )
			|| array_key_exists( 'include_children', $clause )
			|| array_key_exists( 'field', $clause )
			|| array_key_exists( 'operator', $clause );
	}

	/**
	 * Whether a query var carries a value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function has_value( mixed $value ): bool {
		return null !== $value && '' !== $value && false !== $value && array() !== $value;
	}

	/**
	 * Positive integer IDs from a scalar or list; null when an item is not numeric.
	 *
	 * @param mixed $value Value.
	 * @return int[]|null
	 */
	private static function id_list( mixed $value ): ?array {
		if ( ! self::has_value( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( (array) $value as $item ) {
			if ( ! is_numeric( $item ) ) {
				return null;
			}
			$id = abs( (int) $item );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * ASC → asc, anything else → desc (WP_Query::parse_order()).
	 *
	 * @param mixed $order Order.
	 * @return string
	 */
	private static function direction( mixed $order ): string {
		return is_string( $order ) && 'ASC' === strtoupper( $order ) ? 'asc' : 'desc';
	}

	/**
	 * A meta value as a list (arrays as-is, strings split on commas/whitespace like WordPress).
	 *
	 * @param mixed $raw Value.
	 * @return array<int, mixed>
	 */
	private static function value_list( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			return array_values( $raw );
		}
		if ( is_string( $raw ) ) {
			$parts = preg_split( '/[,\s]+/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );
			return false === $parts ? array() : $parts;
		}
		return array( $raw );
	}

	/**
	 * Numbers only; numeric strings are converted; null when an item is not numeric.
	 *
	 * @param array<int, mixed> $raw Values.
	 * @return array<int, int|float>|null
	 */
	private static function numbers( array $raw ): ?array {
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_int( $item ) || is_float( $item ) ) {
				if ( is_float( $item ) && ! is_finite( $item ) ) {
					return null;
				}
				$out[] = $item;
				continue;
			}
			if ( ! is_string( $item ) ) {
				return null;
			}
			$trimmed = trim( $item );
			if ( ! is_numeric( $trimmed ) || ! is_finite( (float) $trimmed ) ) {
				return null;
				// "1e999" is numeric but not a valid filter number.
			}
			$out[] = $trimmed + 0;
		}
		return $out;
	}

	/**
	 * Scalars; numeric strings become numbers because the index stores them as numbers (spec § 5.3).
	 *
	 * @param array<int, mixed> $raw Values.
	 * @return array<int, string|int|float>|null
	 */
	private static function scalars( array $raw ): ?array {
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_int( $item ) || is_float( $item ) ) {
				if ( is_float( $item ) && ! is_finite( $item ) ) {
					return null;
				}
				$out[] = $item;
				continue;
			}
			if ( ! is_string( $item ) ) {
				return null;
			}
			$trimmed = trim( $item );
			if ( is_numeric( $trimmed ) ) {
				if ( ! is_finite( (float) $trimmed ) ) {
					return null;
				}
				$out[] = $trimmed + 0;
			} else {
				$out[] = $item;
			}
		}//end foreach
		return $out;
	}
}
