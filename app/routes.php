<?php
// Routes

$app->get('/{city-id}[/{city-name}[/]]', App\Action\WeatherChannelAction::class);
