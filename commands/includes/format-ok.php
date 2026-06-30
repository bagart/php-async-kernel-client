<?php

declare(strict_types=1);

/**
 * @param  array{url: string, name: string, parseResponse: callable}  $source
 */
return static function (array $source, float $rate): string {
    return str_pad('['.trim($source['name']).']', 35)." USD/EUR: {$rate}";
};
