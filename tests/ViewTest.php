<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Stream;
use Lift\Http\Uri;
use Lift\View\ViewFactory;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    public function testRendersLayoutSectionsPartialsAndAssets(): void
    {
        $dir = sys_get_temp_dir() . '/lift_views_' . bin2hex(random_bytes(4));
        mkdir($dir . '/partials', 0775, true);
        file_put_contents($dir . '/layout.php', '<title><?= $view->yield("title") ?></title><link href="<?= $view->asset("app.css") ?>"><?= $view->content() ?>');
        file_put_contents($dir . '/partials/card.php', '<article><?= $view->e($text) ?></article>');
        file_put_contents($dir . '/home.php', '<?php $view->layout("layout"); $view->section("title"); ?>Home<?php $view->end(); ?><h1><?= $view->e($name) ?></h1><?= $view->include("partials.card", ["text" => "<safe>"]) ?>');

        $factory = new ViewFactory($dir, assetBase: '/static');
        $html = $factory->render('home', ['name' => '<Lift>']);

        self::assertStringContainsString('<title>Home</title>', $html);
        self::assertStringContainsString('/static/app.css', $html);
        self::assertStringContainsString('<h1>&lt;Lift&gt;</h1>', $html);
        self::assertStringContainsString('<article>&lt;safe&gt;</article>', $html);
    }

    public function testAppReturnsViewResponse(): void
    {
        $dir = sys_get_temp_dir() . '/lift_views_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/hello.php', 'Hello <?= $view->e($name) ?>');

        $app = new App();
        $app->views($dir);
        $app->get('/hello', fn() => $app->view('hello', ['name' => 'World']));

        $response = $app->handle(new Request('GET', new Uri('http://localhost/hello'), body: Stream::empty()));

        self::assertSame('Hello World', (string) $response->getBody());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }
}
