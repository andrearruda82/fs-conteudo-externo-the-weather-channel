<?php

namespace App\V3\Action;

use App\V2\Service\SimpleXMLExtended;
use Carbon\Carbon;
use Slim\Http\Request,
    Slim\Http\Response;

use Stringy\Stringy as S;
use FileSystemCache;

use Goutte\Client;
use Symfony\Component\HttpClient\HttpClient;

final class WeatherChannelAction
{
    private $city_id,
            $city_name = null,
            $country = null,
            $locale = null,
            $language = null;

    private $path;

    private $goutteClient;

    private $crawlerNow;

    public function __construct() {
        $this->goutteClient = new Client(HttpClient::create([
            'timeout' => 60,
            'verify_peer' => false,
            'verify_host' => false,
        ]));
    }

    public function __invoke(Request $request, Response $response, $args)
    {
        $this->setCityId($args['city-id']);
        $this->setLocale($args['locale']);

        if(!empty($args['city-name']))
            $this->setCityName($args['city-name']);

        $forceFileCached = isset($request->getQueryParams()['forceFileCached']) ? $request->getQueryParams()['forceFileCached'] : false;

        /** @var \Slim\Http\Uri $uri */
        $uri = $request->getUri();
        $this->path = sprintf('%s://%s', $uri->getScheme(), $uri->getHost() . ($uri->getPort() ? ':' .$uri->getPort() : '')) . '/uploads/images/';

        FileSystemCache::$cacheDir = __DIR__ . '/../../../../data/cache/tmp';
        $key = FileSystemCache::generateCacheKey(sprintf('v3.%s.%s', $this->getCityId(), $this->getLocale()));
        $data = FileSystemCache::retrieve($key);

        if($data === false || $forceFileCached == true)
        {
            $srcNow = sprintf('https://weather.com/%s/clima/hoje/l/%s', $this->getLocale(), $this->getCityId());
            $this->crawlerNow = $this->goutteClient->request('GET', $srcNow);

            $srcPublished = sprintf('http://dsx.weather.com/cs/v2/datetime/%s/%s:1:%s', str_replace('-', '_', $this->getLocale()), $this->getCityId(), $this->getCountry());
            $published = json_decode(file_get_contents($srcPublished));

            $data = [
                'info' => [
                    'date' => [
                        'created' => (new \DateTime('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
                        'published' => Carbon::createFromFormat('Y-m-d\TH:i:s.uP',  $published->datetime)->format('Y-m-d H:i:s'),
                    ],
                    'location' => [
                        'city' => $this->getCityName()
                    ],
                ],
                'now' => [
                    'temp' => $this->getNowTemp(),
                    'prospect' => [
                        'temp' => [
                            'max' => $this->getNowProspectTempMax() < $this->getNowProspectTempMin() ? $this->getNowTemp() : $this->getNowProspectTempMax(),
                            'min' => $this->getNowProspectTempMin()
                        ]
                    ],
                    'midia' => [
                        'icon' => $this->getPath() . $this->getNowMidiaId() . '_icon.png',
                        'background' => $this->getPath() . $this->getNowMidiaId() . '_bg.jpg'
                    ]
                ],
                'forecasts' => []
            ];

            $total = $this->crawlerNow
                ->filter('body main div.region-main > div')->eq(4)
                ->filter('section > div > ul > li')
                ->count();
            $idx = 0;
            for ($i = 0; $i < $total; $i++) {
                $item = $this->crawlerNow
                    ->filter('body main div.region-main > div')->eq(4)
                    ->filter('section > div > ul > li')
                    ->eq($i)
                ;

                $data['forecasts'][$idx] = [
                    'weekday' => $item->filter('a > h3 > span')->text(),
                    'phrases' => [
                        'pop' => '',
                        'narrative' => '',
                    ],
                    'temp' => [
                        'max' => (int) $item->filter('a > div[data-testid=SegmentHighTemp] > span')->text(),
                        'min' => (int) $item->filter('a > div[data-testid=SegmentLowTemp] > span')->text()
                    ],
                    'midia' => [
                        'icon' => $this->getPath() . $item->filter('svg')->attr('skycode') . '_icon.png'
                    ]
                ];


                $idx++;
            }

            FileSystemCache::store($key, $data, 3600);
        }

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

        $response->write($xml->asXML());
        $response = $response->withHeader('content-type', 'application/xml; charset=utf-8');
        return $response;
    }

    /**
     * @return mixed
     */
    public function getCityId()
    {
        return $this->city_id;
    }

    /**
     * @param mixed $city_id
     * @return WeatherChannelAction
     */
    public function setCityId($city_id)
    {
        $this->city_id = $city_id;
        $this->country = substr($this->city_id, 0, 2);
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCityName()
    {
        if(is_null($this->city_name))
        {
            $srcUrl = sprintf('https://weather.com/%s/weather/tenday/l/%s:1:%s', $this->getLocale(), $this->getCityId(),$this->getCountry());

            $crawler = $this->goutteClient->request('GET', $srcUrl);

            $selector = 'span[data-testid=PresentationName]';
            $city_name = $crawler->filter($selector)->text();
            if(!strpos($city_name, ',') === false) {
                $city_name = substr($city_name, 0, strpos($city_name, ','));
            }

            $this->city_name = (string) S::create($city_name)->toLowerCase()->titleize(['da', 'de', 'do']);
        }

        return $this->city_name;
    }

    /**
     * @param null $city_name
     * @return WeatherChannelAction
     */
    public function setCityName($city_name)
    {
        $this->city_name = $city_name;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCountry()
    {
        return $this->country;
    }


    /**
     * @return null
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     * @param null $locale
     * @return WeatherChannelAction
     */
    public function setLocale($locale)
    {
        $this->locale = $locale;
        $this->language = substr($this->locale, 0, 2);
        return $this;
    }

    /**
     * @return null
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * @return string
     */
    private function getPath()
    {
        return $this->path;
    }

    private function getNowTemp() {
        return (int) $this->crawlerNow->filter('body main div.region-main section.card span[data-testid=TemperatureValue]')->text();
    }

    private function getNowProspectTempMax() {
        return (int) $this->crawlerNow->filter('body main div.region-main > div')->eq(4)->filter('section > div > ul > li')->eq(0)->filter('a > div[data-testid=SegmentHighTemp] > span')->text();
    }

    private function getNowProspectTempMin() {
        return (int) $this->crawlerNow->filter('body main div.region-main > div')->eq(4)->filter('section > div > ul > li')->eq(0)->filter('a > div[data-testid=SegmentLowTemp] > span')->text();
    }

    private function getNowMidiaId() {
        return (int) $this->crawlerNow->filter('body main div.region-main > div')->eq(4)->filter('section > div > ul > li')->eq(0)->filter('svg')->attr('skycode');
    }
}