<?php

declare(strict_types=1);

use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use Psr\Http\Message\ResponseInterface;

return [
    [
        'url' => 'https://open.er-api.com/v6/latest/EUR',
        'name' => 'open.er-api.com',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = $body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD');
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://www.cbr-xml-daily.ru/daily_json.js',
        'name' => 'cbr-xml-daily.ru',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $usd = $body['Valute']['USD']['Value'] ?? throw new \RuntimeException('Missing Valute.USD.Value');
            $eur = $body['Valute']['EUR']['Value'] ?? throw new \RuntimeException('Missing Valute.EUR.Value');
            return round((float)$eur / (float)$usd, 6);
        },
    ],
    [
        'url' => 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/eur.json',
        'name' => 'cdn.jsdelivr',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = $body['eur']['usd'] ?? throw new \RuntimeException('Missing eur.usd');
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://economia.awesomeapi.com.br/json/last/EUR-USD',
        'name' => 'awesomeapi.com.br',
        'parseResponse' => static function (ResponseInterface $response): float {
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException("HTTP {$response->getStatusCode()}");
            }
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (isset($body['code']) && $body['code'] !== 'OK') {
                throw new ASKNetworkException($body['message'] ?? $body['code']);
            }
            $rate = $body['EURUSD']['bid'] ?? throw new \RuntimeException('Missing EURUSD.bid');
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://api.coinbase.com/v2/prices/EUR-USD/spot',
        'name' => 'coinbase.spot',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = $body['data']['amount'] ?? throw new \RuntimeException('Missing data.amount');
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://api.coinbase.com/v2/exchange-rates?currency=EUR',
        'name' => 'coinbase.rates',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = $body['data']['rates']['USD'] ?? throw new \RuntimeException('Missing data.rates.USD');
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://api.frankfurter.dev/v1/latest?from=EUR&to=USD',
        'name' => 'frankfurter.dev',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = $body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD');
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://api.nbp.pl/api/exchangerates/tables/A/?format=json',
        'name' => 'nbp.pl',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rates = $body[0]['rates'] ?? throw new \RuntimeException('Missing rates');
            $usdRate = null;
            $eurRate = null;
            foreach ($rates as $rate) {
                $code = $rate['code'] ?? '';
                $mid = (float)($rate['mid'] ?? 0);
                if ($code === 'USD') {
                    $usdRate = $mid;
                } elseif ($code === 'EUR') {
                    $eurRate = $mid;
                }
            }
            if ($usdRate === null || $eurRate === null) {
                throw new \RuntimeException('Missing USD or EUR rate');
            }
            return round($eurRate / $usdRate, 6);
        },
    ],
    [
        'url' => 'https://www.bankofcanada.ca/valet/observations/FXUSDCAD,FXEURCAD/json?recent=1',
        'name' => 'bankofcanada.ca',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $observations = $body['observations'] ?? throw new \RuntimeException('Missing observations');
            $latest = end($observations);
            $usdRate = $latest['FXUSDCAD']['v'] ?? throw new \RuntimeException('Missing FXUSDCAD.v');
            $eurRate = $latest['FXEURCAD']['v'] ?? throw new \RuntimeException('Missing FXEURCAD.v');
            if ((float)$usdRate === 0.0 || (float)$eurRate === 0.0) {
                throw new \RuntimeException('Zero rate');
            }
            return round((float)$eurRate / (float)$usdRate, 6);
        },
    ],
    [
        'url' => 'https://api.datero.ro/v1/fx-rates?currency=EUR,USD',
        'name' => 'datero.ro',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $eurRate = null;
            $usdRate = null;
            foreach ($body['items'] as $item) {
                if ($item['currency'] === 'EUR') {
                    $eurRate = (float)$item['rate'];
                } elseif ($item['currency'] === 'USD') {
                    $usdRate = (float)$item['rate'];
                }
            }
            if ($eurRate === null || $usdRate === null) {
                throw new \RuntimeException('Missing EUR or USD rate');
            }
            return round($eurRate / $usdRate, 6);
        },
    ],
    [
        'url' => 'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument/EUR/USD',
        'name' => 'swissquote.com',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $quote = $body[0] ?? throw new \RuntimeException('Empty response');
            $rate = $quote['spreadProfilePrices'][0]['bid'] ?? throw new \RuntimeException('Missing bid');
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://query1.finance.yahoo.com/v8/finance/chart/EURUSD=X',
        'name' => 'yahoo.finance',
        'parseResponse' => static function (ResponseInterface $response): float {
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(
                    "HTTP {$response->getStatusCode()}: ".substr((string)$response->getBody(), 0, 100)
                );
            }
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = $body['chart']['result'][0]['meta']['regularMarketPrice'] ?? throw new \RuntimeException(
                'Missing regularMarketPrice'
            );
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://latest.currency-api.pages.dev/v1/currencies/eur.json',
        'name' => 'currency-api.pages.dev',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = $body['eur']['usd'] ?? throw new \RuntimeException('Missing eur.usd');
            return (float)$rate;
        },
    ],
    [
        'url' => 'https://api.frankfurter.app/latest?from=EUR&to=USD',
        'name' => 'frankfurter.ecb',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://api.exchangerate.host/latest?base=EUR&symbols=USD',
        'name' => 'exchangerate.host',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://api.frankfurter.dev/v1/latest?base=EUR&symbols=USD',
        'name' => 'frankfurter.dev',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://api.coinbase.com/v2/prices/ETH-USD/spot',
        'name' => 'coinbase.eth.usd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data']['amount'] ?? throw new \RuntimeException('Missing amount'));
        },
    ],
    [
        'url' => 'https://api.binance.com/api/v3/ticker/price?symbol=EURUSDT',
        'name' => 'binance.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['price'] ?? throw new \RuntimeException('Missing price'));
        },
    ],
    [
        'url' => 'https://api.kraken.com/0/public/Ticker?pair=ZEURZUSD',
        'name' => 'kraken.eurusd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['result']['ZEURZUSD']['c'][0] ?? throw new \RuntimeException('Missing ticker'));
        },
    ],
    [
        'url' => 'https://api.kucoin.com/api/v1/market/orderbook/level1?symbol=EUR-USDT',
        'name' => 'kucoin.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data']['price'] ?? throw new \RuntimeException('Missing data.price'));
        },
    ],
    [
        'url' => 'https://api.bybit.com/v5/market/tickers?category=spot&symbol=EURUSDT',
        'name' => 'bybit.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['result']['list'][0]['lastPrice'] ?? throw new \RuntimeException('Missing lastPrice'));
        },
    ],
    [
        'url' => 'https://api.coingecko.com/api/v3/simple/price?ids=eur&vs_currencies=usd',
        'name' => 'coingecko.eur',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['eur']['usd'] ?? throw new \RuntimeException('Missing eur.usd'));
        },
    ],
    [
        'url' => 'https://api.nbp.pl/api/exchangerates/rates/a/eur/?format=json',
        'name' => 'nbp.pl.eur',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return 1 / (float)($body['rates'][0]['mid'] ?? throw new \RuntimeException('Missing mid'));
        },
    ],
    [
        'url' => 'https://www.floatrates.com/daily/eur.json',
        'name' => 'floatrates.eur',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['usd']['rate'] ?? throw new \RuntimeException('Missing usd.rate'));
        },
    ],
    [
        'url' => 'https://api.hnb.hr/tecajn-eur/v3?valuta=USD',
        'name' => 'hnb.hr.usd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return 1 / (float)($body[0]['srednji_tecaj'] ?? throw new \RuntimeException('Missing rate'));
        },
    ],
    [
        'url' => 'https://api.exchangerate-api.com/v4/latest/EUR',
        'name' => 'exchangerate-api',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://api.forexrateapi.com/v1/latest?base=EUR&currencies=USD',
        'name' => 'forexrateapi',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://api.fxratesapi.com/latest?base=EUR&currencies=USD',
        'name' => 'fxratesapi',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://api.fxratesapi.com/latest',
        'name' => 'fxratesapi.default',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://api.nbp.pl/api/exchangerates/rates/a/usd/?format=json',
        'name' => 'nbp.usd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return 1 / ((float)($body['rates'][0]['mid'] ?? throw new \RuntimeException('Missing mid')));
        },
    ],
    [
        'url' => 'https://api.bcb.gov.br/dados/serie/bcdata.sgs.10813/dados/ultimos/1?formato=json',
        'name' => 'bcb.brazil',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body[0]['valor'] ?? throw new \RuntimeException('Missing valor'));
        },
    ],
    [
        'url' => 'https://data-api.ecb.europa.eu/service/data/EXR/D.USD.EUR.SP00.A?format=jsondata',
        'name' => 'ecb.usd.eur',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)(
                $body['dataSets'][0]['series']['0:0:0:0']['observations']['0'][0]
                ?? throw new \RuntimeException('Missing ECB value')
            );
        },
    ],
    [
        'url' => 'https://api.db.nomics.world/v22/series/ECB/EXR/D.USD.EUR.SP00.A?observations=1',
        'name' => 'dbnomics.ecb',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)(
                $body['series']['docs'][0]['value']
                ?? throw new \RuntimeException('Missing value')
            );
        },
    ],
    [
        'url' => 'https://api.vatcomply.com/rates?base=EUR',
        'name' => 'vatcomply',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing USD'));
        },
    ],
    [
        'url' => 'https://api.metalpriceapi.com/v1/latest?base=EUR&currencies=USD',
        'name' => 'metalpriceapi',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['EURUSD'] ?? throw new \RuntimeException('Missing EURUSD'));
        },
    ],
    [
        'url' => 'https://api.exchangerate.fun/latest?base=EUR',
        'name' => 'exchangerate.fun',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://cdn.moneyconvert.net/api/latest.json',
        'name' => 'moneyconvert.net',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $usdEur = (float)($body['rates']['EUR'] ?? throw new \RuntimeException('Missing rates.EUR'));
            return round(1 / $usdEur, 6);
        },
    ],
    [
        'url' => 'https://cdn.jsdelivr.net/gh/prebid/currency-file@latest/latest.json',
        'name' => 'prebid.currency-file',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $usdEur = (float)($body['USD']['EUR'] ?? throw new \RuntimeException('Missing USD.EUR'));
            return round(1 / $usdEur, 6);
        },
    ],
    [
        'url' => 'https://api.frankfurter.dev/v2/rate/EUR/USD',
        'name' => 'frankfurter.v2',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rate'] ?? throw new \RuntimeException('Missing rate'));
        },
    ],
    [
        'url' => 'https://api.frankfurter.dev/v2/rates?base=EUR&quotes=USD',
        'name' => 'frankfurter.rates',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body[0]['rate'] ?? throw new \RuntimeException('Missing rate'));
        },
    ],
    [
        'url' => 'https://api.exchangerate.fun/latest?base=USD',
        'name' => 'exchangerate.fun.usd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $eur = (float)($body['rates']['EUR'] ?? throw new \RuntimeException('Missing EUR'));
            return round(1 / $eur, 6);
        },
    ],
    [
        'url' => 'https://api.frankfurter.dev/v2/rate/USD/EUR',
        'name' => 'frankfurter.usdeur.inverse',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = (float)($body['rate'] ?? throw new \RuntimeException('Missing rate'));
            return round(1 / $rate, 6);
        },
    ],
    [
        'url' => 'https://cdn.jsdelivr.net/gh/forge-arcana/kod-data@main/fx/v1/usd.min.json',
        'name' => 'keppet.open.data',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $usdEur = (float)($body['rates']['EUR'] ?? throw new \RuntimeException('Missing USD.EUR'));
            return round(1 / $usdEur, 6);
        },
    ],
    [
        'url' => 'https://open.er-api.com/v6/latest/USD',
        'name' => 'open.er-api.usd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $eur = (float)($body['rates']['EUR'] ?? throw new \RuntimeException('Missing EUR'));
            return round(1 / $eur, 6);
        },
    ],
    [
        'url' => 'https://api.vatcomply.com/rates?base=USD',
        'name' => 'vatcomply.inverse',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $eur = (float)($body['rates']['EUR'] ?? throw new \RuntimeException('Missing EUR'));
            return round(1 / $eur, 6);
        },
    ],
    [
        'url' => 'https://api.coinbase.com/v2/exchange-rates?currency=USD',
        'name' => 'coinbase.usd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $eur = (float)($body['data']['rates']['EUR'] ?? throw new \RuntimeException('Missing EUR'));
            return round(1 / $eur, 6);
        },
    ],
    [
        'url' => 'https://api.coingecko.com/api/v3/exchange_rates',
        'name' => 'coingecko.rates',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $usd = (float)($body['rates']['usd']['value'] ?? throw new \RuntimeException('Missing usd'));
            $eur = (float)($body['rates']['eur']['value'] ?? throw new \RuntimeException('Missing eur'));
            return round($usd / $eur, 6);
        },
    ],
    [
        'url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
        'name' => 'ecb.xml',
        'parseResponse' => static function (ResponseInterface $response): float {
            $xml = simplexml_load_string((string)$response->getBody());
            foreach ($xml->Cube->Cube->Cube as $cube) {
                if ((string)$cube['currency'] === 'USD') {
                    return (float)$cube['rate'];
                }
            }
            throw new \RuntimeException('Missing USD');
        },
    ],
    [
        'url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml',
        'name' => 'ecb.hist90',
        'parseResponse' => static function (ResponseInterface $response): float {
            $xml = simplexml_load_string((string)$response->getBody());
            foreach ($xml->Cube->Cube[0]->Cube as $cube) {
                if ((string)$cube['currency'] === 'USD') {
                    return (float)$cube['rate'];
                }
            }
            throw new \RuntimeException('Missing USD');
        },
    ],
    [
        'url' => 'https://query2.finance.yahoo.com/v8/finance/chart/EURUSD=X?interval=1d',
        'name' => 'yahoo.chart',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)(
                $body['chart']['result'][0]['meta']['regularMarketPrice']
                ?? throw new \RuntimeException('Missing price')
            );
        },
    ],
    [
        'url' => 'https://api.1forge.com/quotes?pairs=EURUSD',
        'name' => '1forge',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body[0]['price'] ?? throw new \RuntimeException('Missing price'));
        },
    ],
    [
        'url' => 'https://api.exchangerate.host/latest?base=EUR&symbols=USD&places=6',
        'name' => 'exchangerate.host.precision',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['rates']['USD'] ?? throw new \RuntimeException('Missing rates.USD'));
        },
    ],
    [
        'url' => 'https://api.currencylayer.com/live?currencies=EUR',
        'name' => 'currencylayer',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $eurUsd = (float)($body['quotes']['USDEUR'] ?? throw new \RuntimeException('Missing USDEUR'));
            return round(1 / $eurUsd, 6);
        },
    ],
    [
        'url' => 'https://www.floatrates.com/daily/usd.json',
        'name' => 'floatrates.usd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $usdEur = (float)($body['eur']['rate'] ?? throw new \RuntimeException('Missing EUR'));
            return round(1 / $usdEur, 6);
        },
    ],
    [
        'url' => 'https://api.exchangerate.host/live?access_key=&source=EUR&currencies=USD',
        'name' => 'exchangerate.host.live',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['quotes']['EURUSD'] ?? throw new \RuntimeException('Missing EURUSD'));
        },
    ],
    [
        'url' => 'https://api.worldtradingdata.com/api/v1/forex?symbol=EUR/USD',
        'name' => 'worldtradingdata',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data'][0]['price'] ?? throw new \RuntimeException('Missing price'));
        },
    ],
    [
        'url' => 'https://api.hnb.hr/tecajn-eur/v3?valuta=USD&valuta=EUR',
        'name' => 'hnb.multi',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $usd = null;
            $eur = null;
            foreach ($body as $item) {
                if (($item['valuta'] ?? '') === 'USD') {
                    $usd = (float)str_replace(',', '.', $item['srednji_tecaj']);
                }
                if (($item['valuta'] ?? '') === 'EUR') {
                    $eur = (float)str_replace(',', '.', $item['srednji_tecaj']);
                }
            }
            if ($usd === null || $eur === null) {
                throw new \RuntimeException('Missing rates');
            }
            return $eur / $usd;
        },
    ],
    [
        'url' => 'https://www.floatrates.com/daily/eur.xml',
        'name' => 'floatrates.xml',
        'parseResponse' => static function (ResponseInterface $response): float {
            $xml = simplexml_load_string((string)$response->getBody());
            foreach ($xml->channel->item as $item) {
                if ((string)$item->targetCurrency === 'USD') {
                    return (float)$item->exchangeRate;
                }
            }
            throw new \RuntimeException('Missing USD');
        },
    ],
    [
        'url' => 'https://www.floatrates.com/daily/usd.xml',
        'name' => 'floatrates.usd.xml',
        'parseResponse' => static function (ResponseInterface $response): float {
            $xml = simplexml_load_string((string)$response->getBody());
            foreach ($xml->channel->item as $item) {
                if ((string)$item->targetCurrency === 'EUR') {
                    return round(1 / (float)$item->exchangeRate, 6);
                }
            }
            throw new \RuntimeException('Missing EUR');
        },
    ],
    [
        'url' => 'https://latest.currency-api.pages.dev/v1/currencies/usd.json',
        'name' => 'currency-api.latest.usd',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = (float)($body['usd']['eur'] ?? throw new \RuntimeException('Missing usd.eur'));
            return round(1 / $rate, 6);
        },
    ],
    [
        'url' => 'https://api.exchangerate.host/convert?from=EUR&to=USD&amount=1',
        'name' => 'exchangerate.convert',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['result'] ?? throw new \RuntimeException('Missing result'));
        },
    ],
    [
        'url' => 'https://api.exchangerate.host/convert?from=USD&to=EUR&amount=1',
        'name' => 'exchangerate.convert.inverse',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $rate = (float)($body['result'] ?? throw new \RuntimeException('Missing result'));
            return round(1 / $rate, 6);
        },
    ],
    [
        'url' => 'https://api.coinbase.com/v2/exchange-rates?currency=USDC',
        'name' => 'coinbase.usdc',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data']['rates']['EUR'] ?? throw new \RuntimeException('Missing EUR'));
        },
    ],
    [
        'url' => 'https://api.kraken.com/0/public/Ticker?pair=EURUSD',
        'name' => 'kraken.eurusd.alt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            foreach (($body['result'] ?? []) as $ticker) {
                return (float)$ticker['c'][0];
            }
            throw new \RuntimeException('Missing ticker');
        },
    ],
    [
        'url' => 'https://api.binance.com/api/v3/ticker/price?symbol=EURUSDC',
        'name' => 'binance.eurusdc',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['price'] ?? throw new \RuntimeException('Missing price'));
        },
    ],
    [
        'url' => 'https://api.mexc.com/api/v3/ticker/price?symbol=EURUSDT',
        'name' => 'mexc.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['price'] ?? throw new \RuntimeException('Missing price'));
        },
    ],
    [
        'url' => 'https://api.huobi.pro/market/detail/merged?symbol=eurtusdt',
        'name' => 'huobi.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['tick']['close'] ?? throw new \RuntimeException('Missing close'));
        },
    ],
    [
        'url' => 'https://api.bitmart.com/spot/v1/ticker?symbol=EUR_USDT',
        'name' => 'bitmart.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data']['last_price'] ?? throw new \RuntimeException('Missing last_price'));
        },
    ],
    [
        'url' => 'https://api.lbank.info/v2/ticker.do?symbol=eur_usdt',
        'name' => 'lbank.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data']['ticker']['latest'] ?? throw new \RuntimeException('Missing latest'));
        },
    ],
    [
        'url' => 'https://api.crypto.com/v2/public/get-ticker?instrument_name=EUR_USDT',
        'name' => 'crypto.com.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['result']['data'][0]['a'] ?? throw new \RuntimeException('Missing ask'));
        },
    ],
    [
        'url' => 'https://api.bybit.com/v5/market/tickers?category=spot&symbol=EURUSDC',
        'name' => 'bybit.eurusdc',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['result']['list'][0]['lastPrice'] ?? throw new \RuntimeException('Missing lastPrice'));
        },
    ],
    [
        'url' => 'https://api.bithumb.com/public/ticker/EUR_USDT',
        'name' => 'bithumb.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data']['closing_price'] ?? throw new \RuntimeException('Missing closing_price'));
        },
    ],
    [
        'url' => 'https://api.coinone.co.kr/public/v2/ticker_new/EURUSDT',
        'name' => 'coinone.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['tickers'][0]['last'] ?? throw new \RuntimeException('Missing last'));
        },
    ],
    [
        'url' => 'https://api.korbit.co.kr/v1/ticker/detailed?currency_pair=eur_usdt',
        'name' => 'korbit.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['last'] ?? throw new \RuntimeException('Missing last'));
        },
    ],
    [
        'url' => 'https://api.zaif.jp/api/1/ticker/eur_usdt',
        'name' => 'zaif.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['last'] ?? throw new \RuntimeException('Missing last'));
        },
    ],
    [
        'url' => 'https://api.woo.org/v1/public/market_trades?symbol=EUR_USDT',
        'name' => 'woo.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data'][0]['price'] ?? throw new \RuntimeException('Missing price'));
        },
    ],
    [
        'url' => 'https://api.deepcoin.com/public/v1/market/ticker?symbol=EURUSDT',
        'name' => 'deepcoin.eurusdt',
        'parseResponse' => static function (ResponseInterface $response): float {
            $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return (float)($body['data']['last'] ?? throw new \RuntimeException('Missing last'));
        },
    ],
];
