<?php

namespace App\V2\Action;

use Slim\Http\Request,
    Slim\Http\Response;

use phpQuery;
use FileSystemCache;
use Stringy\Stringy as S;
use App\V2\Service\SimpleXMLExtended;

use Goutte\Client;
use GuzzleHttp\Client as GuzzleClient;

use Carbon\Carbon,
    Carbon\CarbonTimeZone;

final class WeatherChannelAction
{
    private $city_id,
            $city_name = null,
            $country = null,
            $locale = null,
            $language = null;

    private $path;

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
        $key = FileSystemCache::generateCacheKey(sprintf('v2.%s', $this->getCityId()));
        $data = FileSystemCache::retrieve($key);

        if($data === false || $forceFileCached == true)
        {
            $srcPublished = sprintf('http://dsx.weather.com/cs/v2/datetime/%s/%s:1:%s', str_replace('-', '_', $this->getLocale()), $this->getCityId(), $this->getCountry());
            $published = json_decode(file_get_contents($srcPublished));

            $srcNow = sprintf('http://dsx.weather.com/wxd/v2/MORecord/%s/%s:1:%s', str_replace('-', '_', $this->getLocale()), $this->getCityId(), $this->getCountry());
            $now = json_decode(file_get_contents($srcNow))->MOData;

            $srcNow = sprintf('http://dsx.weather.com/wxd/v2/15DayForecast/%s/%s:1:%s', str_replace('-', '_', $this->getLocale()), $this->getCityId(), $this->getCountry());
            $forecasts = json_decode(file_get_contents($srcNow))->fcstdaily15alluoms->forecasts;

            $data = array(
                'info' => array(
                    'date' => array(
                        'created' => (new \DateTime('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
                        'published' => Carbon::createFromFormat('Y-m-d\TH:i:s.uP',  $published->datetime)->format('Y-m-d H:i:s'),
                    ),
                    'location' => [
                        'city' => $this->getCityName()
                    ],
                ),
                'now' => array(
                    'temp' => (string) $now->tmpC,
                    'prospect' => array(
                        'temp' => array(
                            'max' => (string) $now->tmpMx24C,
                            'min' => (string) $now->tmpMn24C
                        ),
                    ),
                    'midia' => array(
                        'icon' => $this->getPath() . $now->sky . '_icon.png',
                        'background' => $this->getPath() . $now->sky . '_bg.jpg'
                    )
                ),
                'forecasts' => array()
            );

            foreach ($forecasts as $i => $item)
            {
                if (isset($item->day))
                    $period_type = 'day';
                else
                    $period_type = 'night';

                $forecast = $item->$period_type;

                $data['forecasts'][$i] = array(
                    'weekday' => str_replace('-feira', '', $forecast->daypart_name),
                    'phrases' => array(
                        'pop' => $forecast->pop_phrase,
                        'narrative' => $item->metric->narrative
                    ),
                    'temp' => array(
                        'max' => (string) (!empty($item->metric->max_temp) ? $item->metric->max_temp : $item->metric->min_temp),
                        'min' => (string) $item->metric->min_temp
                    ),
                    'midia' => array(
                        'icon' => $this->getPath() . $item->$period_type->icon_code . '_icon.png'
                    )
                );

                if($i >= 4)
                {
                    break;
                }
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
            $srcUrl = sprintf('https://weather.com/%s/weather/today/l/%s:1:%s', $this->getLocale(), $this->getCityId(),$this->getCountry());

            $goutteClient = new Client();
            $guzzleClient = new GuzzleClient(array(
                'timeout' => 60,
                'verify' => false
            ));
            $goutteClient->setClient($guzzleClient);

            $crawler = $goutteClient->request('GET', $srcUrl);

            $city_name = trim($crawler->filter('div.today_nowcard header h1')->text());
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
}