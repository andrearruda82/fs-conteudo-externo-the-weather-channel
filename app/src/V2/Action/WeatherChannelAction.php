<?php

namespace App\V2\Action;

use App\Action\WeatherChannelActionAbstract;
use FileSystemCache;
use Goutte\Client;
use Slim\Http\Request;
use Slim\Http\Response;
use Symfony\Component\HttpClient\HttpClient;

class WeatherChannelAction extends WeatherChannelActionAbstract
{
    private $goutteClient;
    private $crawlerNow;
    private $crawlerTenDay;

    public function __construct() {
        $this->goutteClient = new Client(HttpClient::create([
            'timeout' => 60,
            'verify_peer' => false,
            'verify_host' => false,
        ]));

        parent::__construct('V2');
    }

    public function __invoke(Request $request, Response $response, $args)
    {
        $this->setCityId($args['city-id']);
        $this->setLocale($args['locale']);
        $this->setPathUpload($request->getUri());

        if(!empty($args['city-name']))
            $this->setCityName($args['city-name']);

        if(isset($request->getQueryParams()['forceFileCached']))
            $this->setForceFileCached($request->getQueryParams()['forceFileCached']);

        $data = $this->getData();
        if($data === false || $this->isForceFileCached() == true)
        {
            $this->crawlerNow = $this->goutteClient->request('GET', sprintf('https://weather.com/pt-BR/clima/hoje/l/%s', $this->getCityId()));
            $this->crawlerTenDay = $this->goutteClient->request('GET', sprintf('https://weather.com/%s/weather/tenday/l/%s', $this->getLocale(), $this->getCityId()));

            $json = $this->dataStructure();
            $json->info->date->created = (new \DateTime('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
            $json->info->date->published = $this->getDatePublished()->format('Y-m-d H:i:s');
            $json->info->location->city = $this->getLocationCityName();

            $json->now->temp = $this->getNowTemp();
            $json->now->prospect->temp->max = $this->getNowPropesctTempMax() < $this->getNowPropesctTempMin() ? $this->getNowTemp() : $this->getNowPropesctTempMax();
            $json->now->prospect->temp->min = $this->getNowPropesctTempMin();
            $json->now->midia->icon = sprintf('%s%s_icon.png', $this->getPathUpload(), $this->getNowMidiaId());
            $json->now->midia->background = sprintf('%s%s_bg.jpg', $this->getPathUpload(), $this->getNowMidiaId());

            $json->forecasts[0]->phrases->pop = $this->getForecastNowPhrases();
            $json->forecasts[0]->phrases->narrative = $json->forecasts[0]->phrases->pop;

            for($i = 1; $i <= 4; $i++) {
                $json->forecasts[$i]->weekday = $this->getForecastWeekday($i);
                $json->forecasts[$i]->phrases->pop = $this->getForecastPhrases($i);
                $json->forecasts[$i]->phrases->narrative = $json->forecasts[$i]->phrases->pop;

                $json->forecasts[$i]->temp->max = $this->getForecastTempMax($i);
                $json->forecasts[$i]->temp->min = $this->getForecastTempMin($i);

                $json->forecasts[$i]->midia->icon = sprintf('%s%s_icon.png', $this->getPathUpload(), $this->getForecastMidiaId($i));
            }

            $data = json_decode(json_encode($json), true);
            $this->setData($data);
        }

        $response->write($this->arrayToXml($data)->asXML());
        $response = $response->withHeader('content-type', 'application/xml; charset=utf-8');
        return $response;
    }

    private function getDatePublished() : \DateTime {
        $timestampStr = $this->crawlerNow
            ->filter('body main > div')->eq(1)
            ->filter('div.region-main > div')->eq(0)
            ->filter('section > div > div > div')
            ->text()
        ;

        $time = substr($timestampStr, 6, 5) . ':00';
        $date = date('Y-m-d ' . $time);

        return \DateTime::createFromFormat('Y-m-d H:i:s', $date);
    }

    private function getLocationCityName() : string {
        if(is_null($this->getCityName())) {
            $cityName = $this->crawlerTenDay
                ->filter('body main > div')->eq(1)
                ->filter('div.region-main > div')->eq(0)
                ->filter('section.card > h1 > span > span')
                ->text()
            ;

            $this->setCityName($cityName);
        }

        return $this->getCityName();
    }

    private function getNowTemp() : int {
        return (int) $this->crawlerNow
            ->filter('body main > div')->eq(1)
            ->filter('div.region-main > div')->eq(0)
            ->filter('section > div > div')->eq(1)
            ->filter('div span[data-testid=TemperatureValue]')
            ->text();
    }

    private function getNowPropesctTemp(string $type) : int {
        $type = strtolower(substr($type,0, 3));
        $types = [
            'max' => 1,
            'min' => 2
        ];

        return (int) $this->crawlerNow
            ->filter('body main > div')->eq(1)
            ->filter('div.region-main > div')->eq(0)
            ->filter('section > div > div')->eq(1)
            ->filter('span')->eq($types[$type])
            ->text();
    }

    private function getNowPropesctTempMax() : int {
        return $this->getNowPropesctTemp('max');
    }

    private function getNowPropesctTempMin() : int {
        return $this->getNowPropesctTemp('min');
    }

    private function getNowMidiaId() : int {
        return (int) $this->crawlerNow
            ->filter('body main > div')->eq(1)
            ->filter('div.region-main > div')->eq(0)
            ->filter('section > div > div')->eq(1)
            ->filter('svg')
            ->attr('skycode');
    }

    private function getForecastWeekday(int $position) : string {

        return $this->crawlerTenDay
            ->filter('body main section.card div')->eq(1)
            ->filter('details')->eq($position)
            ->filter('summary > div > div > h2')
            ->text()
        ;
    }

    private function getForecastPhrases(int $position) : string {
        return $this->crawlerTenDay
            ->filter('body main section.card div')->eq(1)
            ->filter('details')->eq($position)
            ->filter('summary > div > div > div[data-testid=wxIcon] > span')
            ->text()
        ;
    }

    private function getForecastTemp(int $position, string $type) : string {
        $type = strtolower(substr($type,0, 3));
        $types = [
            'max' => 0,
            'min' => 1
        ];

        return (int) $this->crawlerTenDay
            ->filter('body main section.card div')->eq(1)
            ->filter('details')->eq($position)
            ->filter('summary > div > div > div[data-testid=detailsTemperature] span[data-testid=TemperatureValue]')->eq($types[$type])
            ->text();
    }

    private function getForecastTempMax(int $position) : string {
        return $this->getForecastTemp($position, 'max');
    }

    private function getForecastTempMin(int $position) : string {
        return $this->getForecastTemp($position, 'min');
    }

    private function getForecastMidiaId(int $position) : int {
        return (int) $this->crawlerTenDay
            ->filter('body main section.card div')->eq(1)
            ->filter('details')->eq($position)
            ->filter('summary > div > div > div[data-testid=wxIcon] > svg')
            ->attr('skycode');
    }

    private function getForecastNowPhrases() : string {
        return $this->crawlerTenDay
            ->filter('body main section.card details[data-testid=ExpandedDetailsCard] > div > div > p')
            ->text();
    }
}