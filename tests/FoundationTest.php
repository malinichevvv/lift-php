<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Console\Commands\MakeCommand;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Database\Connection;
use Lift\Database\Model;
use Lift\Http\FormRequest;
use Lift\Http\JsonResource;
use Lift\Http\Request;
use Lift\Http\Stream;
use Lift\Http\Uri;
use PHPUnit\Framework\TestCase;

class FoundationTest extends TestCase
{
    private function request(array $body = []): Request
    {
        return new Request('POST', new Uri('http://localhost/users'), body: Stream::empty(), parsedBody: $body);
    }

    public function testFormRequestValidatesAndHydrates(): void
    {
        $form = StoreUserForm::fromRequest($this->request(['email' => 'a@example.com', 'age' => '30']));

        self::assertSame('a@example.com', $form->string('email'));
        self::assertSame(30, $form->integer('age'));
    }

    public function testJsonResourceSerializes(): void
    {
        $resource = new UserTestResource(['id' => 5, 'email' => 'a@example.com']);

        self::assertSame(['id' => 5, 'email' => 'a@example.com'], $resource->jsonSerialize());
    }

    public function testModelCanFindRows(): void
    {
        $db = new Connection('sqlite::memory:');
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $db->table('users')->insert(['name' => 'Alice']);

        UserTestModel::setConnection($db);
        $user = UserTestModel::find(1);

        self::assertInstanceOf(UserTestModel::class, $user);
        self::assertSame('Alice', $user->get('name'));
    }

    public function testMakeCommandCreatesSkeleton(): void
    {
        $dir = sys_get_temp_dir() . '/lift_make_' . bin2hex(random_bytes(4));
        mkdir($dir);

        $stdout = fopen('php://temp', 'r+');
        $stderr = fopen('php://temp', 'r+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $command = new MakeCommand('controller');
        $code = $command->execute(
            new Input(['make:controller', 'UserController', '--namespace=App\\Http\\Controllers', '--path=' . $dir]),
            new Output($stdout, $stderr),
        );

        self::assertSame(0, $code);
        self::assertFileExists($dir . '/App/Http/Controllers/UserController.php');
    }
}

final class StoreUserForm extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email', 'age' => 'required|integer'];
    }
}

final class UserTestResource extends JsonResource
{
    public function toArray(): array
    {
        return ['id' => $this->value('id'), 'email' => $this->value('email')];
    }
}

final class UserTestModel extends Model
{
    protected static string $table = 'users';
    protected array $fillable = ['id', 'name'];
}
