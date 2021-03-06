<?php

/**
 * ArrayOption.php
 *
 * @package meilisearch/meilisearch-wordpress
 * @author MeiliSearch <bonjour@meilisearch.com>
 * @copyright 2021 MeiliSearch
 * @license https://github.com/meilisearch/MeiliSearch/blob/master/LICENSE MIT
 * @link https://wordpress.meilisearch.dev
 */

declare(strict_types=1);

namespace MeiliSearch\WordPress;

use function get_option;

/**
 * Handle the WordPress option.
 */
class ArrayOption
{
    public const OPTION_NAME = 'meilisearch_credentials';

    /** @var array<string, mixed> */
    private $option;

    public function __construct()
    {
        $arrayOption = get_option(self::OPTION_NAME);
        $this->option = is_array($arrayOption) ? $arrayOption : [];
    }

    /**
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->option[$key] ?? null;
    }
}
