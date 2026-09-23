<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Registry;

use InvalidArgumentException;
use TamasLabs\LaravelExpenses\Mapping\Mappers\BudgetPocketMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\CategoryMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\CurrencyMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\ExpenseMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\PaymentMethodMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\ProductMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\ProductPriceMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\ShoppingListItemMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\ShoppingListMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\SubcategoryMapper;
use TamasLabs\LaravelExpenses\Mapping\Mappers\UserMapper;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;
use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Models\Expense;
use TamasLabs\LaravelExpenses\Models\PaymentMethod;
use TamasLabs\LaravelExpenses\Models\Product;
use TamasLabs\LaravelExpenses\Models\ProductPrice;
use TamasLabs\LaravelExpenses\Models\ShoppingList;
use TamasLabs\LaravelExpenses\Models\ShoppingListItem;
use TamasLabs\LaravelExpenses\Models\Subcategory;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * The one list of the resources the package serves: what the sync reads,
 * writes and in which order.
 *
 * A new resource of the contract is a new entry here, with its mapper and
 * migration; a test fails until it has one. Bound as a singleton.
 */
final class ResourceRegistry
{
    /**
     * @var array<string, ResourceDefinition>
     */
    private readonly array $definitions;

    /**
     * @var array<string, RecordMapper>
     */
    private array $mappers = [];

    public function __construct()
    {
        // The push order follows the foreign keys: every parent before its
        // children. A product price refers to both an expense and a product.
        // Whether and when a client pushes its user record is the account
        // endpoints' decision (spec 07).
        $definitions = [
            new ResourceDefinition('user', PackageConfig::userModel(), UserMapper::class, Scope::Account, false, null, '1.0'),
            new ResourceDefinition('currency', Currency::class, CurrencyMapper::class, Scope::Global, false, null, '1.1'),
            new ResourceDefinition('category', Category::class, CategoryMapper::class, Scope::Owned, true, 1, '1.0'),
            new ResourceDefinition('subcategory', Subcategory::class, SubcategoryMapper::class, Scope::Owned, true, 2, '1.0'),
            new ResourceDefinition('payment-method', PaymentMethod::class, PaymentMethodMapper::class, Scope::Owned, true, 3, '1.0'),
            new ResourceDefinition('expense', Expense::class, ExpenseMapper::class, Scope::Owned, true, 4, '1.0'),
            new ResourceDefinition('product', Product::class, ProductMapper::class, Scope::Owned, true, 5, '1.0'),
            new ResourceDefinition('product-price', ProductPrice::class, ProductPriceMapper::class, Scope::Owned, true, 6, '1.0'),
            new ResourceDefinition('shopping-list', ShoppingList::class, ShoppingListMapper::class, Scope::Owned, true, 7, '1.0'),
            new ResourceDefinition('shopping-list-item', ShoppingListItem::class, ShoppingListItemMapper::class, Scope::Owned, true, 8, '1.0'),
            new ResourceDefinition('budget-pocket', BudgetPocket::class, BudgetPocketMapper::class, Scope::Owned, true, 9, '1.0'),
        ];

        $this->definitions = array_column($definitions, null, 'name');
    }

    /**
     * Every resource, in the contract's order.
     *
     * @return list<ResourceDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->definitions);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /**
     * @throws InvalidArgumentException When the package serves no such resource.
     */
    public function get(string $name): ResourceDefinition
    {
        return $this->definitions[$name] ?? throw new InvalidArgumentException(sprintf('There is no "%s" resource.', $name));
    }

    /**
     * The resources a client may push, in the order a push applies them.
     *
     * @return list<ResourceDefinition>
     */
    public function pushable(): array
    {
        $pushable = array_values(array_filter(
            $this->definitions,
            static fn (ResourceDefinition $definition): bool => $definition->clientWritable && $definition->applyOrder !== null,
        ));

        usort($pushable, static fn (ResourceDefinition $a, ResourceDefinition $b): int => $a->applyOrder <=> $b->applyOrder);

        return $pushable;
    }

    /**
     * @throws InvalidArgumentException When the package serves no such resource.
     */
    public function mapper(string $name): RecordMapper
    {
        return $this->mappers[$name] ??= new ($this->get($name)->mapper);
    }
}
