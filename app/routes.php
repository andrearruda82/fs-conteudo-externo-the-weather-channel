<?php
// Routes

$app->get('/', App\Action\HomeAction::class)->setName('homepage');
$app->get('/{city-id}[/{city-name}[/]]', App\V1\Action\WeatherChannelAction::class)->setName('v1.weather-channel');
$app->get('/v2/{locale}/{city-id}[/{city-name}[/]]', App\V2\Action\WeatherChannelAction::class)->setName('v2.weather-channel');
