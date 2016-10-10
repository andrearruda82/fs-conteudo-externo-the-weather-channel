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
    private $city_id, $city_name;
    private $locale = 'pt_BR';
    private $path = 'http://conteudo.farolsign.com.br/weather_channel/v1/data/uploads/images/';

    public function __construct()
    {
    }

    public function __invoke(Request $request, Response $response, $args)
    {
        $this->setCityId($args['city-id']);

        if($args['city-name'])
        {
            $this->setCityName($args['city-name']);
        }

        $forceFileCached = isset($request->getQueryParams()['forceFileCached']) ? $request->getQueryParams()['forceFileCached'] : false;

        FileSystemCache::$cacheDir = __DIR__ . '/../../../cache/tmp';
        $key = FileSystemCache::generateCacheKey($this->getCityId());
        $data = FileSystemCache::retrieve($key);

        if($data === false || $forceFileCached == true)
        {
            $published = strtotime(json_decode(file_get_contents('http://dsx.weather.com/cs/v2/datetime/' . $this->getLocale() . '/' . $this->getCityId() . ':1:BR'), true)['datetime']);
            $now = json_decode(file_get_contents('http://dsx.weather.com/wxd/v2/MORecord/' . $this->getLocale() . '/' . $this->getCityId() . ':1:BR'))->MOData;
            $forecasts = json_decode(file_get_contents('http://dsx.weather.com/wxd/v2/15DayForecast/' . $this->getLocale() . '/' . $this->getCityId() . ':1:BR'))->fcstdaily15alluoms->forecasts;

            $data = array(
                'info' => array(
                    'date' => array(
                        'created' => date('Y-m-d H:i:s'),
                        'published' => date('Y-m-d H:i:s', $published),
                    ),
                    'location' => $this->Info()
                ),
                'now' => array(
                    'phrase' => $now->wx,
                    'temp' => $now->tmpC,
                    'feels' => $now->flsLkIdxC,
                    'prospect' => array(
                        'temp' => array(
                            'max' => $now->tmpMx24C,
                            'min' => $now->tmpMn24C
                        ),
                        'sun' => array(
                            'sunrise' => $now->sunrise,
                            'sunset' => $now->sunset
                        )
                    ),
                    'midia' => array(
                        'icon' => $this->getPath() . $now->sky . '_icon.png',
                        'bg' => $this->getPath() . $now->sky . '_bg.jpg',
                    )
                ),
                'forecasts' => ''
            );

            $period_type = date('H') >= 16 ? 'night' : 'day';

            foreach ($forecasts as $i => $item)
            {
                $forecast = $item->$period_type;

                $data['forecasts'][$i] = array(
                    'date' => array(
                        'day' =>  date('Y-m-d', strtotime($forecast->fcst_valid_local)),
                        'weekday' => $forecast->daypart_name
                    ),
                    'phrases' => array(
                        'weather' => $forecast->shortcast,
                        'wind' => $forecast->metric->wind_phrase,
                        'temp' => $forecast->metric->temp_phrase,
                        'lunar' => $item->lunar_phase
                    ),
                    'prospect' => array(
                        'temp' => array(
                            'max' => !empty($item->metric->max_temp) ? $item->metric->max_temp : $item->metric->min_temp,
                            'min' => $item->metric->min_temp
                        ),
                        'sun' => array(
                            'rise' => (string) S::create(date('h:i A' , strtotime($item->sunrise)))->toLowerCase(),
                            'set' => (string) S::create(date('h:i A' , strtotime($item->sunset)))->toLowerCase()
                        ),
                        'moon' => array(
                            'rise' => (string) S::create(date('h:i A' , strtotime($item->moonrise)))->toLowerCase(),
                            'set' => (string) S::create(date('h:i A' , strtotime($item->moonset)))->toLowerCase()
                        ),
                        'midia' => array(
                            'icon' => $this->getPath() . $item->$period_type->icon_code . '_icon.png'
                        )
                    )
                );

                if($i >= 4)
                {
                    break;
                }
            }

            FileSystemCache::store($key, $data, 7200);

        }

        $xmlBuilder = new XmlBuilder('root');
        $xmlBuilder->setSingularizer(function ($name) {
            if ('airports' === $name || 'forecasts' === $name) {
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
    private function Info()
    {
        $doc = phpQuery::newDocumentFileHTML('http://www.weather.com/pt-BR/clima/hoje/l/' . $this->getCityId() . ':1:BR');
        $doc->find('link')->remove();
        $doc->find('meta')->remove();

        $html = $doc['head']->html();
        preg_match("/\window.explicit_location_obj = (.*);/", $html, $matche); 		//https://regex101.com/r/gQ7hA1/1
        $location = json_decode($matche[1]);

        $city_name = !empty($this->getCityName()) ? $this->getCityName() : $location->cityNm;

        $data = array(
            'id' => $location->locId,
            'city' => (string) S::create($city_name)->toLowerCase()->titleize(['da', 'de', 'do']),
            'state' => $location->stNm,
            'country' => $location->_country,
            'position' => array(
                'lat' => $location->lat,
                'long' => $location->long
            ),
            'airports' => ''
        );

        if(isset($location->_arptNear))
        {
            foreach ($location->_arptNear as $key => $item)
            {
                $data['airports'][$key] = array(
                    'name' => $item,
                    'dist' => $location->_arptNearDist[$key]->dist
                );
            }
        }

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
}
