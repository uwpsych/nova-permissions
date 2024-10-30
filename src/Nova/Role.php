<?php
namespace Sereny\NovaPermissions\Nova;

use Illuminate\Database\Query\Builder;
use Laravel\Nova\Fields\ID;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Text;
use Illuminate\Validation\Rule;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Http\Requests\NovaRequest;
use Sereny\NovaPermissions\Fields\Checkboxes;
use Sereny\NovaPermissions\Models\Role as RoleModel;

class Role extends Resource
{
    /**
     * Indicates if the resource should be displayed in the sidebar.
     *
     * @var bool
     */
    public static $displayInNavigation = false;

    /**
     * The list of field name that should be hidden
     *
     * @var string[]
     */
    public static $hiddenFields = [];

    /**
     * The model the resource corresponds to.
     *
     * @var string
     */
    public static $model = RoleModel::class;

    /**
     * The columns that should be searched.
     *
     * @var array
     */
    public static $search = [
        'name',
    ];

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    /**
     * Indicates if the resource should be displayed in the sidebar.
     *
     */
    public static $with = [
        'permissions',
    ];

    /**
     * Get the fields displayed by the resource.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest $request
     * @return array
     */
    public function fields(NovaRequest $request)
    {
        $guardOptions = $this->guardOptions($request);
        $userResource = $this->userResource();

        return [
            ID::make(__('ID'), 'id')
                ->rules('required')
                ->canSee(function ($request) {
                    return $this->fieldAvailable('id');
                }),

            Text::make(__('Name'), 'name')
                ->rules(['required', 'string', 'max:255'])
                ->creationRules('unique:' . config('permission.table_names.roles'))
                ->updateRules('unique:' . config('permission.table_names.roles') . ',name,{{resourceId}}'),

            Select::make(__('Guard Name'), 'guard_name')
                ->options($guardOptions->toArray())
                ->rules(['required', Rule::in($guardOptions)])
                ->canSee(function ($request) {
                    return $this->fieldAvailable('guard_name');
                })
                ->default($this->defaultGuard($guardOptions)),

            Checkboxes::make(__('Permissions'), 'permissions')
                ->options($this->loadPermissions()->map(function ($permission, $key) {
                    return [
                        'group'  => __(ucfirst($permission->group)),
                        'option' => $permission->name,
                        'label'  => __($permission->name),
                    ];
                })
                    ->groupBy('group')
                    ->toArray()),

            Text::make(__('Users'), function () {
                /**
                 * We eager load count for the users relationship in the index query.
                 * @see self::indexQuery()
                 */
                return isset($this->users_count) ? $this->users_count : $this->users()->count();
            })->exceptOnForms(),

            MorphToMany::make($userResource::label(), 'users', $userResource)
                ->searchable()
                ->canSee(function ($request) {
                    return $this->fieldAvailable('users');
                }),
        ];
    }

    public static function label()
    {
        return __('Roles');
    }

    public static function singularLabel()
    {
        return __('Role');
    }

    /**
     * Let's eager load the user count within the "index" query.
     */
    public static function indexQuery(NovaRequest $request, $query)
    {
        /** @var Builder|PermissionModel $query */
        return parent::indexQuery($request, $query)->withCount('users');
    }

    /**
     * Load all permissions and cache for 1 minute.
     * Enough to avoid N+1 at the Role index page,
     * and not long enough to have them stalled.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function loadPermissions()
    {
        $expirationTime = config('permission.cache.nova_expiration_time', now()->addMinute());

        return cache()->remember('sereny-nova-permissions', $expirationTime, function () {
            /** @var class-string */
            $permissionClass = config('permission.models.permission');

            return $permissionClass::all()->unique('name');
        });
    }
}
