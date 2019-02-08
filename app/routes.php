<?php
// Routes

$app->get('/{city-id}[/{city-name}[/]]', App\V1\Action\WeatherChannelAction::class);
$app->get('/v2/{locale}/{city-id}', App\V2\Action\WeatherChannelAction::class);
