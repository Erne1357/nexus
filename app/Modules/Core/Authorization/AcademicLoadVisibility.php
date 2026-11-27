<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * El interruptor de la carga académica (MN-05, tarea 1.24.6): el admin la oculta en «Parámetros» (`core.academic_load_visible`)
 * y entonces nadie más la ve ni la abre. Ocultar no borra ni detiene nada: los datos, los trabajos en cola, los comandos y
 * las notificaciones siguen igual.
 *
 * Las pantallas del jefe de departamento y del JPD (MN-06, tarea 1.56): con su perfil activo, si trabajan la carga de un
 * departamento, ven y abren solo las pantallas de la carga de las etapas en que está la de su departamento (ROUTE_STAGES y
 * LoadStages), con la carga visible u oculta; con ella oculta, solo si el admin deja encendido «Mostrar a jefes y JPD las
 * pantallas de su etapa» (`core.academic_load_heads_by_stage`). Las corridas del motor no son de ninguna etapa: no las ven
 * por ahora.
 *
 * Lo que oculta se decide en dos lugares, y una prueba (AcademicLoadVisibilityTest) los mantiene de acuerdo:
 * - el menú, por el área «Carga académica» (NavigationArea::CargaAcademica): sus entradas salen del menú, del buscador, de la
 *   banda y de los atajos de Inicio (NavigationItem::isVisibleTo);
 * - las rutas, por la lista explícita ROUTES, que revisa EnsureAcademicLoadIsVisible en toda petición web y en cada acción
 *   de Livewire (middleware persistente). `home` y `map.*` (el mapa, la entrada a Nexus) nunca se bloquean, aunque alguien
 *   los agregue a la lista.
 * Los dos preguntan lo mismo por ruta (closesFor), y la campana también (withoutHiddenNotifications, OpenNotification).
 *
 * Quien tiene `core.academic_load.view_hidden` (solo el admin: es candado, CF-03) la sigue viendo y abriendo, con la marca
 * «Oculta para los demás» (MN-02).
 *
 * `scoped` (Octane): lee los dos parámetros una vez por petición, y las etapas de cada usuario y perfil una vez también.
 * ForgetAcademicLoadVisibility lo olvida si cambian en la misma petición (SettingsUpdated) y al terminar cada una
 * (RequestHandled), también sin Octane.
 */
final class AcademicLoadVisibility
{
    public const string SETTING = 'core.academic_load_visible';

    public const bool DEFAULT_VISIBLE = true;

    /**
     * Si los jefes y JPD ven las pantallas de su etapa mientras la carga está oculta (MN-06).
     */
    public const string HEADS_SETTING = 'core.academic_load_heads_by_stage';

    public const bool DEFAULT_HEADS_BY_STAGE = true;

    /**
     * Los perfiles que ven la carga por etapa (MN-06, confirmado el 26 de noviembre de 2026).
     *
     * @var list<Role>
     */
    public const array HEADS = [Role::JefeDepartamento, Role::JefeProyectoDocente];

    /**
     * El motivo con que el admin ve marcada la carga en el menú y en el aviso de la pantalla (MN-02).
     */
    public const string HIDDEN_REASON = 'La carga académica está oculta en Parámetros';

    /**
     * Las rutas de la carga académica que se bloquean mientras está oculta, por nombre: las de la propuesta, la bandeja (con
     * Paralelos), la demanda y la precisión de la proyección, las versiones publicadas, los horarios y los choques, los acuerdos
     * (préstamos de salón y grupos compartidos), el re-análisis, la asignación docente, la carga por docente, las solicitudes
     * de cambio, el cierre y su avance, las corridas del motor, y la consulta publicada con sus PDF y
     * exportes. Las que piden sesión responden 403; las públicas, 404.
     *
     * @var list<string>
     */
    public const array ROUTES = [
        'academic-load.proposal',
        'academic-load.inbox',
        'academic-load.demand',
        'academic-load.forecast-accuracy',
        'academic-load.versions',
        'published.index',
        'published.semester',
        'published.teacher',
        'published.room',
        'published.semester.pdf',
        'published.teacher.pdf',
        'published.room.pdf',
        'published.export',
        'scheduling.editor',
        'scheduling.conflicts',
        'scheduling.agreements',
        'scheduling.parallels',
        'scheduling.reanalysis',
        'staffing.assign',
        'staffing.teacher-loads',
        'staffing.change-requests',
        'staffing.closing',
        'staffing.closing-board',
        'solver.runs',
    ];

    /**
     * Las etapas de cada ruta de ROUTES (MN-06, supuesto por confirmar): el jefe y el JPD la ven y la abren si su carga está
     * en alguna. Toda ruta de ROUTES lleva la suya (una prueba lo revisa); una ruta sin etapas no la ven nunca, y una que se
     * ve en cualquier etapa las lista todas. Las corridas del motor no tienen ninguna «por ahora» (confirmado el 26 de
     * noviembre de 2026): se activan dándoles etapa.
     *
     * @var array<string, list<Stage>>
     */
    public const array ROUTE_STAGES = [
        'academic-load.proposal' => [Stage::Proposal],
        'academic-load.inbox' => [Stage::Proposal],
        'academic-load.demand' => [Stage::Proposal],
        'academic-load.forecast-accuracy' => [Stage::Proposal],
        'academic-load.versions' => [Stage::Publication, Stage::Reanalysis],
        'published.index' => [Stage::Publication],
        'published.semester' => [Stage::Publication],
        'published.teacher' => [Stage::Publication],
        'published.room' => [Stage::Publication],
        'published.semester.pdf' => [Stage::Publication],
        'published.teacher.pdf' => [Stage::Publication],
        'published.room.pdf' => [Stage::Publication],
        'published.export' => [Stage::Publication],
        'scheduling.editor' => [Stage::Scheduling, Stage::Rooms],
        'scheduling.conflicts' => [Stage::Scheduling, Stage::Rooms],
        'scheduling.agreements' => [Stage::Scheduling, Stage::Rooms],
        'scheduling.parallels' => [Stage::Proposal],
        'scheduling.reanalysis' => [Stage::Reanalysis],
        'staffing.assign' => [Stage::Staffing],
        'staffing.teacher-loads' => [Stage::Staffing],
        'staffing.change-requests' => [Stage::Staffing, Stage::Closing, Stage::Publication, Stage::Reanalysis],
        'staffing.closing' => [Stage::Closing],
        'staffing.closing-board' => [Stage::Closing],
        'solver.runs' => [],
    ];

    /**
     * Rutas que nunca se bloquean: Inicio y el mapa.
     *
     * @var list<string>
     */
    public const array NEVER_HIDDEN = ['home', 'map.*'];

    private ?bool $visible = null;

    private ?bool $headsByStage = null;

    /** @var array<string, list<Stage>> por usuario, perfil y periodo de carga */
    private array $stages = [];

    /** @var array<string, bool> si trabaja la carga de un departamento, por usuario y perfil */
    private array $heads = [];

    public function __construct(private readonly LoadStages $loadStages) {}

    /**
     * Si la ruta es de la carga académica: está en la lista (ROUTES, salvo en las pruebas) y no es Inicio ni del mapa.
     *
     * @param  list<string>  $routes
     */
    public static function isLoadRoute(?string $route, array $routes = self::ROUTES): bool
    {
        return $route !== null && ! Str::is(self::NEVER_HIDDEN, $route) && in_array($route, $routes, true);
    }

    /**
     * Si el admin la ocultó: el valor guardado si es sí o no; si no hay o no es válido, el de omisión (visible).
     */
    public function isHidden(): bool
    {
        $this->read();

        return ! $this->visible;
    }

    /**
     * Si está oculta para el usuario del contexto: lo está y no tiene `core.academic_load.view_hidden`. Es la carga entera,
     * sin etapas: lo preguntan «Mi carga» y las solicitudes de cambio del docente (MN-05). Las pantallas de la carga
     * preguntan closesFor().
     */
    public function hiddenFrom(Actor $context): bool
    {
        return $this->isHidden() && ! $context->allowsAny(CorePermission::AcademicLoadViewHidden);
    }

    /**
     * Si la pantalla de la carga `$route` (una de ROUTES) está cerrada para el usuario del contexto: no la ve en el menú, en
     * la banda ni en la campana, y no la abre.
     * - Al jefe y al JPD (perfil activo) que trabajan la carga de un departamento, si no es de ninguna de las etapas de su
     *   carga (MN-06), o si la carga está oculta y el admin apagó «Mostrar a jefes y JPD las pantallas de su etapa».
     * - A los demás, si la carga está oculta para ellos (hiddenFrom(), MN-05).
     *
     * La etapa es la de su periodo de carga, o la de `$term` (el periodo de un aviso, que OpenNotification deja en su
     * credencial al abrirlo).
     */
    public function closesFor(Actor $context, string $route, ?Term $term = null): bool
    {
        if (! $this->seesByStage($context)) {
            return $this->hiddenFrom($context);
        }

        if ($this->isHidden() && ! $this->headsSeeTheirStage()) {
            return true;
        }

        $stages = $this->stagesOf($context, $term);

        return ! array_any(self::ROUTE_STAGES[$route] ?? [], fn (Stage $stage): bool => in_array($stage, $stages, true));
    }

    /**
     * Si el usuario ve la carga oculta como el admin, con la marca «Oculta para los demás» (MN-02): está oculta y tiene
     * `core.academic_load.view_hidden`. El jefe que ve su etapa con ella oculta no lleva la marca.
     */
    public function marksHiddenFor(Actor $context): bool
    {
        return $this->isHidden() && $context->allowsAny(CorePermission::AcademicLoadViewHidden);
    }

    /**
     * Acota una consulta de `notifications` a lo que el usuario del contexto puede abrir: sin los avisos que llevan a una
     * pantalla de la carga cerrada para él (closesFor: oculta, o fuera de la etapa del jefe; la campana no los cuenta, no los
     * muestra ni los marca leídos; no se borran y vuelven cuando la pantalla se abre). Si las abre todas, la deja igual. Al
     * jefe y al JPD, cada aviso con la etapa de su periodo (`data.term_id`), como lo abre OpenNotification.
     *
     * @template TQuery of Builder<DatabaseNotification>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function withoutHiddenNotifications(Builder|QueryBuilder $query, Actor $context): Builder|QueryBuilder
    {
        if ($this->seesByStage($context)) {
            return $this->withinStages($query, $context);
        }

        if (! $this->hiddenFrom($context)) {
            return $query;
        }

        return $query->where(fn (Builder|QueryBuilder $notifications) => $notifications
            ->whereNull('data->route')
            ->orWhereNotIn('data->route', self::ROUTES));
    }

    /**
     * Olvida lo leído, para que la siguiente pregunta lo vuelva a leer.
     */
    public function forget(): void
    {
        $this->visible = null;
        $this->headsByStage = null;
        $this->stages = [];
        $this->heads = [];
    }

    /**
     * El parámetro para el catálogo de Core (CF-S7): solo lo cambia quien tiene `core.academic_load.toggle` (el admin);
     * `core.setting.update` no basta.
     */
    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            self::SETTING,
            'core',
            'Módulos',
            label: 'Mostrar la carga académica',
            help: 'Apagado, la carga académica (propuesta, demanda, horarios y choques, acuerdos, asignación docente, carga por docente, solicitudes de cambio, corridas del motor, cierre, publicación, sin «Mi carga», «Oferta» ni el horario de la orden de selección, que siempre se ven) sale del menú y sus pantallas no abren, ni la consulta pública de horarios, para nadie más que el admin y, si el parámetro siguiente lo deja, los jefes de departamento y los JPD en las pantallas de su etapa. No se borra ni se detiene nada.',
            type: SettingType::Boolean,
            default: self::DEFAULT_VISIBLE,
            permission: CorePermission::AcademicLoadToggle,
            permissionOnly: true,
        );
    }

    /**
     * El parámetro de los jefes y JPD (MN-06), junto al interruptor y con su mismo candado.
     */
    public static function headsDefinition(): SettingDefinition
    {
        return new SettingDefinition(
            self::HEADS_SETTING,
            'core',
            'Módulos',
            label: 'Mostrar a jefes y JPD las pantallas de su etapa',
            help: 'Vale mientras «Mostrar la carga académica» está apagado: encendido, los jefes de departamento y los JPD siguen viendo y abriendo las pantallas de la etapa en que está la carga de su departamento; apagado, tampoco ellos la ven. Con la carga visible no cambia nada: ellos siempre ven solo las de su etapa, sin las corridas del motor.',
            type: SettingType::Boolean,
            default: self::DEFAULT_HEADS_BY_STAGE,
            permission: CorePermission::AcademicLoadToggle,
            permissionOnly: true,
        );
    }

    /**
     * Los avisos del jefe y del JPD que puede abrir (MN-06): los que no llevan a la carga y los que llevan a una pantalla de
     * la etapa de su carga en el periodo del aviso, o en su periodo de carga si el aviso no trae uno o ya no existe. Una
     * consulta más para los periodos de sus avisos de la carga y una para las etapas de cada uno.
     *
     * @template TQuery of Builder<DatabaseNotification>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function withinStages(Builder|QueryBuilder $query, Actor $context): Builder|QueryBuilder
    {
        $termIds = (clone ($query instanceof Builder ? $query->toBase() : $query))
            ->reorder()
            ->whereIn('data->route', self::ROUTES)
            ->whereNotNull('data->term_id')
            ->selectRaw("distinct data->>'term_id' as term_id")
            ->pluck('term_id')
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id));
        $terms = $termIds->isEmpty() ? collect() : Term::query()->whereKey($termIds->all())->get()->keyBy(fn (Term $term): string => (string) $term->getKey());
        $closed = fn (?Term $term): array => array_values(array_filter(self::ROUTES, fn (string $route): bool => $this->closesFor($context, $route, $term)));

        return $query->where(function (Builder|QueryBuilder $notifications) use ($terms, $closed): void {
            $notifications->whereNull('data->route')
                ->orWhere(fn (Builder|QueryBuilder $own) => $own
                    ->where(fn (Builder|QueryBuilder $term) => $term->whereNull('data->term_id')->orWhereNotIn('data->term_id', $terms->keys()->all()))
                    ->whereNotIn('data->route', $closed(null)));

            foreach ($terms as $id => $term) {
                $notifications->orWhere(fn (Builder|QueryBuilder $other) => $other->where('data->term_id', $id)->whereNotIn('data->route', $closed($term)));
            }
        });
    }

    /**
     * Si el usuario del contexto ve la carga por etapa (MN-06): su perfil activo es jefe de departamento o JPD y trabaja la
     * carga de un departamento (LoadStages::worksLoad; el jefe de Servicios Escolares no, AZ-02). Una vez por usuario y
     * perfil en la petición.
     */
    private function seesByStage(Actor $context): bool
    {
        $role = $context->activeRole();

        if (! in_array($role, self::HEADS, true)) {
            return false;
        }

        return $this->heads[$context->user()?->getKey().'|'.$role->value] ??= $this->loadStages->worksLoad($context);
    }

    /**
     * Si los jefes y JPD ven su etapa con la carga oculta: el valor guardado si es sí o no; si no, el de omisión (sí).
     */
    private function headsSeeTheirStage(): bool
    {
        $this->read();

        return $this->headsByStage;
    }

    /**
     * Las etapas de la carga del usuario del contexto en `$term` o en su periodo de carga, una vez por usuario, perfil y
     * periodo en la petición.
     *
     * @return list<Stage>
     */
    private function stagesOf(Actor $context, ?Term $term): array
    {
        $term ??= $context->loadTerm();
        $key = implode('|', [$context->user()?->getKey(), $context->activeRole()?->value, $term?->getKey()]);

        return $this->stages[$key] ??= $this->loadStages->of($context, $term);
    }

    /**
     * Lee los dos parámetros en una consulta, la primera vez.
     */
    private function read(): void
    {
        if ($this->visible !== null && $this->headsByStage !== null) {
            return;
        }

        $values = Setting::query()
            ->where('scope', SettingScope::Global)->whereNull('scope_id')->whereIn('key', [self::SETTING, self::HEADS_SETTING])
            ->get()->pluck('value', 'key');

        $visible = $values->get(self::SETTING);
        $heads = $values->get(self::HEADS_SETTING);

        $this->visible = is_bool($visible) ? $visible : self::DEFAULT_VISIBLE;
        $this->headsByStage = is_bool($heads) ? $heads : self::DEFAULT_HEADS_BY_STAGE;
    }
}
