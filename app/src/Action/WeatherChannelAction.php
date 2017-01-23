<?php
namespace App\Action;

use Slim\Http\Request,
    Slim\Http\Response;

use Thapp\XmlBuilder\XMLBuilder,
    Thapp\XmlBuilder\Normalizer;

use phpQuery;
use FileSystemCache;
use Stringy\Stringy as S;

final class WeatherChannelAction
{
    private $city_id, $city_name, $city_country;
    private $locale;
    private $path = 'http://conteudo.farolsign.com.br/weather_channel/v1/data/uploads/images/';

    public function __construct()
    {
    }

    public function __invoke(Request $request, Response $response, $args)
    {
        $this->setCityId($args['city-id']);

        if(isset($args['city-name']))
        {
            $this->setCityName($args['city-name']);
        }

        $forceFileCached = isset($request->getQueryParams()['forceFileCached']) ? $request->getQueryParams()['forceFileCached'] : false;

        FileSystemCache::$cacheDir = __DIR__ . '/../../../cache/tmp';
        $key = FileSystemCache::generateCacheKey($this->getCityId());
        $data = FileSystemCache::retrieve($key);

        if($data === false || $forceFileCached == true)
        {
            $published = strtotime(json_decode(file_get_contents('http://dsx.weather.com/cs/v2/datetime/' . $this->getLocale() . '/' . $this->getCityId() . ':1:' . $this->getCityCountry()), true)['datetime']);
            $now = json_decode(file_get_contents('http://dsx.weather.com/wxd/v2/MORecord/' . $this->getLocale() . '/' . $this->getCityId() . ':1:' . $this->getCityCountry()))->MOData;
            $forecasts = json_decode(file_get_contents('http://dsx.weather.com/wxd/v2/15DayForecast/' . $this->getLocale() . '/' . $this->getCityId() . ':1:' . $this->getCityCountry()))->fcstdaily15alluoms->forecasts;

            $data = array(
                'info' => array(
                    'date' => array(
                        'created' => date('Y-m-d H:i:s'),
                        'published' => date('Y-m-d H:i:s', $published),
                    ),
                    'location' => $this->getLocation(),
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

            FileSystemCache::store($key, $data, 1800);

        }

        $xmlBuilder = new XmlBuilder('root');
        $xmlBuilder->setSingularizer(function ($name){
            if ('forecasts' === $name)
            {
                return 'item';
            }

            return $name;
        });
        $xmlBuilder->load($data);
        $xml_output = $xmlBuilder->createXML(true);
        $response->write($xml_output);
        $response = $response->withHeader('content-type', 'application/xml; charset=utf-8');
        return $response;
    }

    /**
     * @return mixed
     */
    private function getCityId()
    {
        return $this->city_id;
    }

    /**
     * @param mixed $city_id
     */
    private function setCityId($city_id)
    {
        $this->city_id = $city_id;
        $this->city_country = substr($this->city_id, 0, 2);

        $locales = [
            'BR' => 'pt_BR',
            'AR' => 'es_AR',
            'US' => 'en_US'
        ];

        if(array_key_exists(strtoupper($this->city_country), $locales))
            $this->locale = $locales[strtoupper($this->city_country)];
        else
            $this->locale = 'pt_BR';
    }

    /**
     * @return string
     */
    private function getLocale()
    {
        return $this->locale;
    }

    /**
     * @return array
     */
    private function getLocation()
    {
        $doc = phpQuery::newDocumentFileHTML('http://www.weather.com/pt-BR/clima/hoje/l/' . $this->getCityId() . ':1:' . $this->getCityCountry());
        $doc->find('link')->remove();
        $doc->find('meta')->remove();

        $html = $doc['head']->html();
        preg_match("/\window.explicit_location_obj = (.*);/", $html, $matche); 		//https://regex101.com/r/gQ7hA1/1
        $location = json_decode($matche[1]);

        $city_name = !empty($this->getCityName()) ? $this->getCityName() : $location->cityNm;

        $data = array(
            'city' => (string) S::create($city_name)->toLowerCase()->titleize(['da', 'de', 'do']),
        );

        return $data;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return mixed
     */
    public function getCityName()
    {
        return $this->city_name;
    }

    /**
     * @param mixed $city_name
     */
    public function setCityName($city_name)
    {
        $this->city_name = mb_check_encoding($city_name, 'UTF-8') ? $city_name : utf8_encode($city_name);
    }

    /**
     * @return mixed
     */
    public function getCityCountry()
    {
        return $this->city_country;
    }
}