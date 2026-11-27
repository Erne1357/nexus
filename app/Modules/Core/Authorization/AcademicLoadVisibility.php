<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
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
 * Lo que oculta se decide en dos lugares, y una prueba (AcademicLoadVisibilityTest) los mantiene de acuerdo:
 * - el menú, por el área «Carga académica» (NavigationArea::CargaAcademica): sus entradas salen del menú, del buscador, de la
 *   banda y de los atajos de Inicio (NavigationItem::isVisibleTo);
 * - las rutas, por la lista explícita ROUTES, que revisa EnsureAcademicLoadIsVisible en toda petición web y en cada acción
 *   de Livewire (middleware persistente). `home` y `map.*` (el mapa, la entrada a Nexus) nunca se bloquean, aunque alguien
 *   los agregue a la lista.
 *
 * Quien tiene `core.academic_load.view_hidden` (solo el admin: es candado, CF-03) la sigue viendo y abriendo, con la marca
 * «Oculta para los demás» (MN-02).
 *
 * `scoped` (Octane): lee el parámetro una vez por petición. ForgetAcademicLoadVisibility lo olvida si cambia en la misma
 * petición (SettingsUpdated) y al terminar cada una (RequestHandled), también sin Octane.
 */
final class AcademicLoadVisibility
{
    public const string SETTING = 'core.academic_load_visible';

    public const bool DEFAULT_VISIBLE = true;

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
        'staffing.current-load',
        'staffing.assign',
        'staffing.teacher-loads',
        'staffing.change-requests',
        'staffing.closing',
        'staffing.closing-board',
        'solver.runs',
    ];

    /**
     * Rutas que nunca se bloquean: Inicio y el mapa.
     *
     * @var list<string>
     */
    public const array NEVER_HIDDEN = ['home', 'map.*'];

    private ?bool $visible = null;

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
        if ($this->visible === null) {
            $value = Setting::query()
                ->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', self::SETTING)
                ->first()?->value;

            $this->visible = is_bool($value) ? $value : self::DEFAULT_VISIBLE;
        }

        return ! $this->visible;
    }

    /**
     * Si está oculta para el usuario del contexto: lo está y no tiene `core.academic_load.view_hidden`.
     */
    public function hiddenFrom(Actor $context): bool
    {
        return $this->isHidden() && ! $context->allowsAny(CorePermission::AcademicLoadViewHidden);
    }

    /**
     * Acota una consulta de `notifications` a lo que el usuario del contexto puede abrir: sin los avisos que llevan a una
     * pantalla de la carga mientras la tiene oculta (la campana no los cuenta, no los muestra ni los marca leídos; no se
     * borran y vuelven al mostrarla). Si la ve, la deja igual.
     *
     * @template TQuery of Builder<DatabaseNotification>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function withoutHiddenNotifications(Builder|QueryBuilder $query, Actor $context): Builder|QueryBuilder
    {
        if (! $this->hiddenFrom($context)) {
            return $query;
        }

        return $query->where(fn (Builder|QueryBuilder $notifications) => $notifications
            ->whereNull('data->route')
            ->orWhereNotIn('data->route', self::ROUTES));
    }

    /**
     * Olvida el valor leído, para que la siguiente pregunta lo vuelva a leer.
     */
    public function forget(): void
    {
        $this->visible = null;
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
            help: 'Apagado, la carga académica (propuesta, demanda, horarios y choques, acuerdos, asignación docente, carga por docente, solicitudes de cambio, corridas del motor, cierre, publicación, sin «Mi carga», «Oferta» ni el horario de la orden de selección, que siempre se ven) sale del menú y sus pantallas no abren, ni la consulta pública de horarios, para nadie más que el admin. No se borra ni se detiene nada.',
            type: SettingType::Boolean,
            default: self::DEFAULT_VISIBLE,
            permission: CorePermission::AcademicLoadToggle,
            permissionOnly: true,
        );
    }
}
