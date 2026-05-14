<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Stream;
use Lift\Http\Uri;
use Lift\Translation\Translator;
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

    public function testViewTranslation(): void
    {
        $dir = sys_get_temp_dir() . '/lift_views_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/msg.php', '<?= $view->t("greeting", ["name" => $name]) ?>');

        $t = new Translator('en');
        $t->addMessages('en', ['greeting' => 'Hello, :name!']);

        $factory = new ViewFactory($dir);
        $factory->setTranslator($t);

        $html = $factory->render('msg', ['name' => 'World']);
        self::assertSame('Hello, World!', $html);
    }

    public function testViewTranslationPlural(): void
    {
        $dir = sys_get_temp_dir() . '/lift_views_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/count.php', '<?= $view->tc("items", $n) ?>');

        $t = new Translator('en');
        $t->addMessages('en', ['items' => 'one item|many items']);

        $factory = new ViewFactory($dir);
        $factory->setTranslator($t);

        self::assertSame('one item',   $factory->render('count', ['n' => 1]));
        self::assertSame('many items', $factory->render('count', ['n' => 5]));
    }

    public function testViewTranslationFallsBackToKey(): void
    {
        $dir = sys_get_temp_dir() . '/lift_views_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/key.php', '<?= $view->t("unknown.key") ?>');

        // No translator configured
        $factory = new ViewFactory($dir);
        self::assertSame('unknown.key', $factory->render('key'));
    }

    public function testViewTranslationInPartial(): void
    {
        $dir = sys_get_temp_dir() . '/lift_views_' . bin2hex(random_bytes(4));
        mkdir($dir . '/parts', 0775, true);
        file_put_contents($dir . '/parts/nav.php', '<nav><?= $view->t("nav.home") ?></nav>');
        file_put_contents($dir . '/page.php', '<?= $view->include("parts.nav") ?>');

        $t = new Translator('en');
        $t->addMessages('en', ['nav.home' => 'Home']);

        $factory = (new ViewFactory($dir))->setTranslator($t);
        self::assertSame('<nav>Home</nav>', $factory->render('page'));
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
