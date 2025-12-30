<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\GraphQL\GraphQL;
use HybridPHP\Core\GraphQL\Schema;
use HybridPHP\Core\GraphQL\SchemaBuilder;
use HybridPHP\Core\GraphQL\Type\ObjectType;
use HybridPHP\Core\GraphQL\Type\ListType;
use HybridPHP\Core\GraphQL\Type\NonNullType;
use HybridPHP\Core\GraphQL\Type\EnumType;
use HybridPHP\Core\GraphQL\Parser\Parser;
use HybridPHP\Core\GraphQL\Parser\Lexer;
use HybridPHP\Core\GraphQL\DataLoader\DataLoader;
use function Amp\async;

/**
 * GraphQL unit tests
 */
class GraphQLTest extends TestCase
{
    private GraphQL $graphql;
    private Schema $schema;

    protected function setUp(): void
    {
        $userType = new ObjectType([
            'name' => 'User',
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
                'email' => ['type' => 'String'],
            ],
        ]);

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'hello' => [
                    'type' => 'String',
                    'args' => [
                        'name' => ['type' => 'String', 'defaultValue' => 'World'],
                    ],
                    'resolve' => fn($root, $args) => "Hello, {$args['name']}!",
                ],
                'user' => [
                    'type' => $userType,
                    'args' => [
                        'id' => ['type' => new NonNullType('ID')],
                    ],
                    'resolve' => fn($root, $args) => [
                        'id' => $args['id'],
                        'name' => 'Test User',
                        'email' => 'test@example.com',
                    ],
                ],
                'users' => [
                    'type' => new ListType($userType),
                    'resolve' => fn() => [
                        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
                        ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
                    ],
                ],
            ],
        ]);

        $mutationType = new ObjectType([
            'name' => 'Mutation',
            'fields' => [
                'createUser' => [
                    'type' => $userType,
                    'args' => [
                        'name' => ['type' => new NonNullType('String')],
                        'email' => ['type' => new NonNullType('String')],
                    ],
                    'resolve' => fn($root, $args) => [
                        'id' => 123,
                        'name' => $args['name'],
                        'email' => $args['email'],
                    ],
                ],
            ],
        ]);

        $this->schema = new Schema([
            'query' => $queryType,
            'mutation' => $mutationType,
            'types' => [$userType],
        ]);

        $this->graphql = new GraphQL($this->schema);
    }

    public function testSimpleQuery(): void
    {
        $result = $this->graphql->execute('{ hello }')->await();

        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('Hello, World!', $result['data']['hello']);
    }

    public function testQueryWithArguments(): void
    {
        $result = $this->graphql->execute('{ hello(name: "GraphQL") }')->await();

        $this->assertEquals('Hello, GraphQL!', $result['data']['hello']);
    }

    public function testQueryWithVariables(): void
    {
        $query = 'query GetHello($name: String!) { hello(name: $name) }';
        $variables = ['name' => 'Variables'];

        $result = $this->graphql->execute($query, $variables)->await();

        $this->assertEquals('Hello, Variables!', $result['data']['hello']);
    }

    public function testObjectTypeQuery(): void
    {
        $result = $this->graphql->execute('{ user(id: "1") { id name email } }')->await();

        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('1', $result['data']['user']['id']);
        $this->assertEquals('Test User', $result['data']['user']['name']);
        $this->assertEquals('test@example.com', $result['data']['user']['email']);
    }

    public function testListQuery(): void
    {
        $result = $this->graphql->execute('{ users { id name } }')->await();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']['users']);
        $this->assertEquals('Alice', $result['data']['users'][0]['name']);
        $this->assertEquals('Bob', $result['data']['users'][1]['name']);
    }

    public function testMutation(): void
    {
        $query = 'mutation { createUser(name: "Charlie", email: "charlie@example.com") { id name email } }';
        $result = $this->graphql->execute($query)->await();

        $this->assertArrayHasKey('data', $result);
        $this->assertEquals(123, $result['data']['createUser']['id']);
        $this->assertEquals('Charlie', $result['data']['createUser']['name']);
        $this->assertEquals('charlie@example.com', $result['data']['createUser']['email']);
    }

    public function testFieldAlias(): void
    {
        $result = $this->graphql->execute('{ greeting: hello }')->await();

        $this->assertArrayHasKey('greeting', $result['data']);
        $this->assertEquals('Hello, World!', $result['data']['greeting']);
    }

    public function testMultipleFields(): void
    {
        $result = $this->graphql->execute('{ hello user(id: "1") { name } }')->await();

        $this->assertArrayHasKey('hello', $result['data']);
        $this->assertArrayHasKey('user', $result['data']);
    }

    public function testTypename(): void
    {
        $result = $this->graphql->execute('{ user(id: "1") { __typename name } }')->await();

        $this->assertEquals('User', $result['data']['user']['__typename']);
    }

    public function testInvalidQuery(): void
    {
        $result = $this->graphql->execute('{ invalid }')->await();

        // Should return null for unknown field
        $this->assertNull($result['data']['invalid']);
    }

    public function testSyntaxError(): void
    {
        $result = $this->graphql->execute('{ hello( }')->await();

        $this->assertArrayHasKey('errors', $result);
        $this->assertNotEmpty($result['errors']);
    }

    public function testBatchExecution(): void
    {
        $queries = [
            ['query' => '{ hello }'],
            ['query' => '{ users { name } }'],
        ];

        $results = $this->graphql->executeBatch($queries)->await();

        $this->assertCount(2, $results);
        $this->assertEquals('Hello, World!', $results[0]['data']['hello']);
        $this->assertCount(2, $results[1]['data']['users']);
    }

    public function testValidation(): void
    {
        $errors = $this->graphql->validate('{ hello }');
        $this->assertEmpty($errors);
    }

    public function testValidationWithInvalidOperation(): void
    {
        // Create schema without subscription
        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => ['hello' => ['type' => 'String', 'resolve' => fn() => 'hi']],
            ]),
        ]);
        $graphql = new GraphQL($schema);

        $errors = $graphql->validate('subscription { test }');
        $this->assertNotEmpty($errors);
    }

    // Lexer tests
    public function testTokenizeSimpleQuery(): void
    {
        $lexer = new Lexer('{ hello }');
        $tokens = $lexer->tokenize();

        $this->assertCount(4, $tokens); // { hello } EOF
        $this->assertEquals(Lexer::T_BRACE_L, $tokens[0]['type']);
        $this->assertEquals(Lexer::T_NAME, $tokens[1]['type']);
        $this->assertEquals('hello', $tokens[1]['value']);
        $this->assertEquals(Lexer::T_BRACE_R, $tokens[2]['type']);
        $this->assertEquals(Lexer::T_EOF, $tokens[3]['type']);
    }

    public function testTokenizeWithArguments(): void
    {
        $lexer = new Lexer('{ hello(name: "World") }');
        $tokens = $lexer->tokenize();

        $this->assertGreaterThan(4, count($tokens));
        
        // Find string token
        $stringToken = null;
        foreach ($tokens as $token) {
            if ($token['type'] === Lexer::T_STRING) {
                $stringToken = $token;
                break;
            }
        }
        
        $this->assertNotNull($stringToken);
        $this->assertEquals('World', $stringToken['value']);
    }

    public function testTokenizeNumbers(): void
    {
        $lexer = new Lexer('{ field(int: 42, float: 3.14) }');
        $tokens = $lexer->tokenize();

        $intToken = null;
        $floatToken = null;
        
        foreach ($tokens as $token) {
            if ($token['type'] === Lexer::T_INT) {
                $intToken = $token;
            }
            if ($token['type'] === Lexer::T_FLOAT) {
                $floatToken = $token;
            }
        }

        $this->assertNotNull($intToken);
        $this->assertEquals('42', $intToken['value']);
        $this->assertNotNull($floatToken);
        $this->assertEquals('3.14', $floatToken['value']);
    }

    public function testTokenizeSpread(): void
    {
        $lexer = new Lexer('{ ...fragment }');
        $tokens = $lexer->tokenize();

        $spreadToken = null;
        foreach ($tokens as $token) {
            if ($token['type'] === Lexer::T_SPREAD) {
                $spreadToken = $token;
                break;
            }
        }

        $this->assertNotNull($spreadToken);
        $this->assertEquals('...', $spreadToken['value']);
    }

    // Parser tests
    public function testParseSimpleQuery(): void
    {
        $parser = new Parser('{ hello }');
        $document = $parser->parse();

        $this->assertCount(1, $document->definitions);
        $this->assertInstanceOf(
            \HybridPHP\Core\GraphQL\Parser\OperationDefinitionNode::class,
            $document->definitions[0]
        );
    }

    public function testParseNamedQuery(): void
    {
        $parser = new Parser('query GetHello { hello }');
        $document = $parser->parse();

        $operation = $document->definitions[0];
        $this->assertEquals('query', $operation->operation);
        $this->assertEquals('GetHello', $operation->name);
    }

    public function testParseMutation(): void
    {
        $parser = new Parser('mutation CreateUser { createUser(name: "Test") { id } }');
        $document = $parser->parse();

        $operation = $document->definitions[0];
        $this->assertEquals('mutation', $operation->operation);
    }

    public function testParseWithVariables(): void
    {
        $parser = new Parser('query GetUser($id: ID!) { user(id: $id) { name } }');
        $document = $parser->parse();

        $operation = $document->definitions[0];
        $this->assertCount(1, $operation->variableDefinitions);
        $this->assertEquals('id', $operation->variableDefinitions[0]->name);
    }

    public function testParseFragment(): void
    {
        $query = '
            query { users { ...UserFields } }
            fragment UserFields on User { id name }
        ';
        $parser = new Parser($query);
        $document = $parser->parse();

        $this->assertCount(2, $document->definitions);
    }

    public function testParseDirectives(): void
    {
        $parser = new Parser('{ hello @skip(if: true) }');
        $document = $parser->parse();

        $operation = $document->definitions[0];
        $field = $operation->selectionSet->selections[0];
        
        $this->assertCount(1, $field->directives);
        $this->assertEquals('skip', $field->directives[0]->name);
    }

    // DataLoader tests
    public function testLoadSingleValue(): void
    {
        $loader = new DataLoader(function (array $keys) {
            return async(fn() => array_map(fn($k) => "value_{$k}", $keys));
        });

        $result = $loader->load('key1')->await();
        $this->assertEquals('value_key1', $result);
    }

    public function testLoadMany(): void
    {
        $loader = new DataLoader(function (array $keys) {
            return async(fn() => array_map(fn($k) => "value_{$k}", $keys));
        });

        $results = $loader->loadMany(['key1', 'key2', 'key3'])->await();
        
        $this->assertCount(3, $results);
        $this->assertEquals('value_key1', $results[0]);
        $this->assertEquals('value_key2', $results[1]);
        $this->assertEquals('value_key3', $results[2]);
    }

    public function testCaching(): void
    {
        $callCount = 0;
        
        $loader = new DataLoader(function (array $keys) use (&$callCount) {
            $callCount++;
            return async(fn() => array_map(fn($k) => "value_{$k}", $keys));
        });

        // Load same key twice
        $loader->load('key1')->await();
        $loader->load('key1')->await();

        // Batch function should only be called once due to caching
        $this->assertEquals(1, $callCount);
    }

    public function testClear(): void
    {
        $callCount = 0;
        
        $loader = new DataLoader(function (array $keys) use (&$callCount) {
            $callCount++;
            return async(fn() => array_map(fn($k) => "value_{$k}", $keys));
        });

        $loader->load('key1')->await();
        $loader->clear('key1');
        
        // Need to wait for next batch
        \Amp\delay(0.01);
        $loader->load('key1')->await();

        $this->assertEquals(2, $callCount);
    }

    public function testPrime(): void
    {
        $callCount = 0;
        
        $loader = new DataLoader(function (array $keys) use (&$callCount) {
            $callCount++;
            return async(fn() => array_map(fn($k) => "value_{$k}", $keys));
        });

        // Prime the cache
        $loader->prime('key1', 'primed_value');

        $result = $loader->load('key1')->await();
        
        $this->assertEquals('primed_value', $result);
        $this->assertEquals(0, $callCount); // Batch function not called
    }

    // Schema tests
    public function testCreateSchema(): void
    {
        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'hello' => ['type' => 'String'],
                ],
            ]),
        ]);

        $this->assertNotNull($schema->getQueryType());
        $this->assertEquals('Query', $schema->getQueryType()->getName());
    }

    public function testBuiltInTypes(): void
    {
        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => ['test' => ['type' => 'String']],
            ]),
        ]);

        $this->assertTrue($schema->hasType('String'));
        $this->assertTrue($schema->hasType('Int'));
        $this->assertTrue($schema->hasType('Float'));
        $this->assertTrue($schema->hasType('Boolean'));
        $this->assertTrue($schema->hasType('ID'));
    }

    public function testSchemaValidation(): void
    {
        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'hello' => ['type' => 'String'],
                ],
            ]),
        ]);

        $errors = $schema->validate();
        $this->assertEmpty($errors);
    }

    public function testValidationFailsWithoutQuery(): void
    {
        $schema = new Schema([]);
        $errors = $schema->validate();
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Query', $errors[0]);
    }

    // SchemaBuilder tests
    public function testBuildSchema(): void
    {
        $schema = SchemaBuilder::create()
            ->query([
                'name' => 'Query',
                'fields' => [
                    'hello' => ['type' => 'String'],
                ],
            ])
            ->build();

        $this->assertInstanceOf(Schema::class, $schema);
        $this->assertNotNull($schema->getQueryType());
    }

    public function testObjectTypeFactory(): void
    {
        $type = SchemaBuilder::objectType('User', [
            'fields' => [
                'id' => ['type' => 'ID'],
                'name' => ['type' => 'String'],
            ],
        ]);

        $this->assertInstanceOf(ObjectType::class, $type);
        $this->assertEquals('User', $type->getName());
    }

    public function testEnumTypeFactory(): void
    {
        $type = SchemaBuilder::enumType('Status', [
            'ACTIVE' => 'active',
            'INACTIVE' => 'inactive',
        ]);

        $this->assertInstanceOf(EnumType::class, $type);
        $this->assertEquals('Status', $type->getName());
    }

    public function testListOfFactory(): void
    {
        $type = SchemaBuilder::listOf('String');

        $this->assertInstanceOf(ListType::class, $type);
        $this->assertEquals('[String]', $type->getName());
    }

    public function testNonNullFactory(): void
    {
        $type = SchemaBuilder::nonNull('String');

        $this->assertInstanceOf(NonNullType::class, $type);
        $this->assertEquals('String!', $type->getName());
    }
}
