<?php
namespace App\Action;

    use Slim\Views\Twig;
    use Slim\Http\Request;
    use Slim\Http\Response;

final class HomeAction
{
    private $view;
    public function __construct(Twig $view)
    {
        $this->view = $view;
    }
    public function __invoke(Request $request, Response $response, $args)
    {
        $this->view->render($response, 'home.twig');
        return $response;
    }
}