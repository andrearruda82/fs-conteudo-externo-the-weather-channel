<?php

namespace App\Action;

use App\V2\Service\SimpleXMLExtended;
use FileSystemCache;
use FileSystemCacheKey;
use Slim\Http\Uri;

abstract class WeatherChannelActionAbstract
{
    const TTL = 3600;
    private $pathUpload = null;
    private $city_id = null;
    private $city_name = null;
    private $locale = null;
    private $keyCacheStrg = '';
    private $keyCache = null;
    private $forceFileCached = false;
    private $data = false;

    public function __construct($keyCacheStrg = 'cache')
    {
        $this->keyCacheStrg = $keyCacheStrg;
        FileSystemCache::$cacheDir = __DIR__ . '/../../../data/cache/tmp';
    }

    public function setPathUpload(Uri $uri) : self {
        if(is_null($this->pathUpload))
            $this->pathUpload = sprintf('%s://%s', $uri->getScheme(), $uri->getHost() . ($uri->getPort() ? ':' .$uri->getPort() : '')) . '/uploads/images/';

        return $this;
    }

    public function getPathUpload() : ?string {
        return $this->pathUpload;
    }

    public function setCityId($cityId) : self {
        $this->city_id = $cityId;
        return $this;
    }

    public function getCityId() : ?string {
        return $this->city_id;
    }

    public function setCityName($cityName) : self {
        $this->city_name = $cityName;
        return $this;
    }

    public function getCityName() : ?string {
        return $this->city_name;
    }

    public function setLocale($locale) : self {
        $this->locale = $locale;
        return $this;
    }

    public function getLocale() : ?string {
        return $this->locale;
    }

    public function getKeyCache() : ?FileSystemCacheKey {
        if(is_null($this->keyCache))
            $this->keyCache = FileSystemCache::generateCacheKey(sprintf('%s.%s.%s', $this->keyCacheStrg, $this->getCityId(), $this->getLocale()));

        return $this->keyCache;
    }

    public function isForceFileCached(): bool
    {
        return $this->forceFileCached;
    }

    public function setForceFileCached(bool $forceFileCached) : self
    {
        $this->forceFileCached = $forceFileCached;
        return $this;
    }

    public function getData() {
        return FileSystemCache::retrieve($this->getKeyCache());
    }

    public function setData(array $data) : self{
        FileSystemCache::store($this->getKeyCache(), $data, self::TTL);
        return $this;
    }

    public function arrayToXml(array $data) : SimpleXMLExtended {
        $json = json_decode(json_encode($data));

        $xml = new SimpleXMLExtended('<root/>');
        $info = $xml->addChild('info');
        $info->addChild('date');
        $info->date->addChild('created', $json->info->date->created);
        $info->date->addChild('published', $json->info->date->published);
        $info->addChild('location')->addChild('city', $json->info->location->city);

        $now = $xml->addChild('now');
        $now->addChild('temp', $json->now->temp);
        $now->addChild('prospect');
        $now->prospect->addChild('temp');
        $now->prospect->temp->addChild('max', $json->now->prospect->temp->max);
        $now->prospect->temp->addChild('min', $json->now->prospect->temp->min);
        $now->addChild('midia');
        $now->midia->addChild('icon', $json->now->midia->icon);
        $now->midia->addChild('background', $json->now->midia->background);

        $forecasts = $xml->addChild('forecasts');
        foreach ($json->forecasts as $forecast)
        {
            $item = $forecasts->addChild('item');
            $item->addChild('weekday', $forecast->weekday);
            $item->addChild('phrases')->addChild('pop', $forecast->phrases->pop);
            $item->phrases->addChild('narrative', $forecast->phrases->narrative);
            $item->addChild('temp');
            $item->temp->addChild('max', $forecast->temp->max);
            $item->temp->addChild('min', $forecast->temp->min);
            $item->addChild('midia')->addChild('icon', $forecast->midia->icon);
        }

        return $xml;
    }

    public function dataStructure() {
        $data = [
            'info' => [
                'date' => [
                    'created' => '',
                    'published' => '',
                ],
                'location' => [
                    'city' => ''
                ],
            ],
            'now' => [
                'temp' => '',
                'prospect' => [
                    'temp' => [
                        'max' => '',
                        'min' => ''
                    ]
                ],
                'midia' => [
                    'icon' => '',
                    'background' => ''
                ]
            ],
            'forecasts' => []
        ];

        for($i = 0; $i <= 4; $i++) {
            $data['forecasts'][$i] = [
                'weekday' => '',
                'phrases' => [
                    'pop' => '',
                    'narrative' => '',
                ],
                'temp' => [
                    'max' => '',
                    'min' => ''
                ],
                'midia' => [
                    'icon' => ''
                ]
            ];
        }

        return json_decode(json_encode($data));
    }
}