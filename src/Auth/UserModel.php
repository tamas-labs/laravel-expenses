<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use LogicException;
use TamasLabs\LaravelExpenses\HasExpensesProfile;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * What the account endpoints need of the host's user model (spec 07, 4.3),
 * and the typed way to hold one of its instances.
 */
final class UserModel
{
    /**
     * Checked when the package boots, so a host missing a piece hears it at
     * once rather than on the first sign-in.
     *
     * @throws LogicException When the model lacks a trait or an interface.
     */
    public static function check(): void
    {
        $model = PackageConfig::userModel();
        $traits = class_uses_recursive($model);
        $missing = [];

        foreach ([HasExpensesProfile::class, HasApiTokens::class] as $trait) {
            if (! \in_array($trait, $traits, true)) {
                $missing[] = "use the {$trait} trait";
            }
        }

        foreach ([Authenticatable::class, MustVerifyEmail::class] as $interface) {
            if (! is_subclass_of($model, $interface)) {
                $missing[] = "implement {$interface}";
            }
        }

        if ($missing !== []) {
            throw new LogicException(sprintf('The user model %s (expenses.user_model) must %s.', $model, implode(', ', $missing)));
        }
    }

    /**
     * A new, unsaved user.
     *
     * @return Model&Authenticatable&MustVerifyEmail
     */
    public static function make(): Model
    {
        $model = PackageConfig::userModel();

        return self::of(new $model);
    }

    /**
     * The value as a user of the configured model.
     *
     * @return Model&Authenticatable&MustVerifyEmail
     *
     * @throws LogicException When it is not one.
     */
    public static function of(mixed $user): Model
    {
        if ($user instanceof Model && $user instanceof Authenticatable && $user instanceof MustVerifyEmail) {
            return $user;
        }

        throw new LogicException(sprintf('Expected a user of the expenses.user_model model; got %s.', get_debug_type($user)));
    }
}
