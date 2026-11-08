<?php

namespace App\Http\Controllers;

use App\Models\Casillero;
use App\Models\Organizacion;
use App\Services\CodigoOrganizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * LockSense - CRUD de organizaciones (solo SUPERADMIN).
 *
 *   GET    /api/superadmin/organizaciones            listar
 *   POST   /api/superadmin/organizaciones            crear (con código y 6 casilleros)
 *   PUT    /api/superadmin/organizaciones/{id}       modificar
 *   PATCH  /api/superadmin/organizaciones/{id}/estado  dar de baja / reactivar
 *
 * No se borran organizaciones: se dan de baja (estado = 'I') para
 * conservar su historial (usuarios, casilleros, accesos, eventos).
 */
class OrganizacionController extends Controller
{
    private const ROL_SUPERADMIN = 1;

    // Organización interna del superadmin: no se lista ni se modifica.
    private const TIPO_SISTEMA = 'Sistema';

    // Deben ser los mismos que en el formulario del front y en el registro.
    public const TIPOS = [
        'Empresa / Corporativo',
        'Fábrica / Planta Industrial',
        'Escuela Pública',
        'Escuela Privada / Universidad',
        'Gimnasio',
        'Centro Deportivo',
        'Hospital / Clínica',
        'Hotel / Hospedaje',
        'Otro',
    ];

    // Igual que en el registro: cada organización nueva recibe 6 casilleros.
    private const CASILLEROS_POR_ORGANIZACION = 6;
    private const ESTADO_CASILLERO_INICIAL = 'Libre';

    public function index(Request $request): JsonResponse
    {
        $this->soloSuperadmin($request);

        $organizaciones = Organizacion::query()
            ->where(fn ($q) => $q->whereNull('tipo')->orWhere('tipo', '!=', self::TIPO_SISTEMA))
            ->withCount('casilleros')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Organizacion $org) => $this->formatear($org));

        return response()->json([
            'organizaciones' => $organizaciones,
            'tipos'          => self::TIPOS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->soloSuperadmin($request);

        $datos = $this->validar($request);

        $organizacion = DB::transaction(function () use ($datos) {
            $organizacion = Organizacion::create(array_merge($datos, ['estado' => 'A']));

            // Código corto (ej. "UTN"), prefijo de las matrículas.
            CodigoOrganizacion::asegurar($organizacion);

            $this->crearCasilleros($organizacion->id);

            return $organizacion;
        });

        return response()->json([
            'message'      => 'Organización registrada correctamente.',
            'organizacion' => $this->formatear($organizacion->fresh()->loadCount('casilleros')),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->soloSuperadmin($request);

        $organizacion = $this->buscar($id);
        $datos = $this->validar($request, $organizacion->id);

        // El código NO se regenera al cambiar el nombre: las matrículas
        // de sus usuarios ya lo usan como prefijo.
        $organizacion->update($datos);

        return response()->json([
            'message'      => 'Organización actualizada correctamente.',
            'organizacion' => $this->formatear($organizacion->fresh()->loadCount('casilleros')),
        ]);
    }

    public function cambiarEstado(Request $request, int $id): JsonResponse
    {
        $this->soloSuperadmin($request);

        $datos = $request->validate([
            'estado' => ['required', Rule::in(['A', 'I'])],
        ]);

        $organizacion = $this->buscar($id);
        $organizacion->update(['estado' => $datos['estado']]);

        return response()->json([
            'message'      => $datos['estado'] === 'A'
                ? 'Organización reactivada.'
                : 'Organización dada de baja.',
            'organizacion' => $this->formatear($organizacion->fresh()->loadCount('casilleros')),
        ]);
    }

    // ==================================================
    // Auxiliares
    // ==================================================

    private function soloSuperadmin(Request $request): void
    {
        abort_unless((int) $request->user()->roles_id === self::ROL_SUPERADMIN, 403,
            'Solo el superadministrador puede gestionar organizaciones.');
    }

    /** Busca una organización real (nunca la del sistema). */
    private function buscar(int $id): Organizacion
    {
        $organizacion = Organizacion::findOrFail($id);

        abort_if($organizacion->tipo === self::TIPO_SISTEMA, 403,
            'La organización del sistema no se puede modificar.');

        return $organizacion;
    }

    private function validar(Request $request, ?int $ignorarId = null): array
    {
        // Limpieza antes de validar: espacios y teléfono solo con números.
        $request->merge([
            'nombre'        => trim((string) $request->input('nombre')),
            'telefono'      => preg_replace('/\D/', '', (string) $request->input('telefono')),
            'direccion'     => trim((string) $request->input('direccion')),
            'colonia'       => trim((string) $request->input('colonia')) ?: null,
            'ciudad'        => trim((string) $request->input('ciudad')),
            'codigo_postal' => trim((string) $request->input('codigo_postal')) ?: null,
        ]);

        $validator = Validator::make($request->all(), [
            'nombre'        => ['required', 'string', 'min:3', 'max:150'],
            'tipo'          => ['required', Rule::in(self::TIPOS)],
            'telefono'      => ['required', 'digits:10'],
            'direccion'     => ['required', 'string', 'max:225'],
            'colonia'       => ['nullable', 'string', 'max:100'],
            'ciudad'        => ['required', 'string', 'max:100'],
            'codigo_postal' => ['nullable', 'digits:5'],
        ], [
            'nombre.required'      => 'El nombre es obligatorio.',
            'nombre.min'           => 'El nombre debe tener al menos 3 caracteres.',
            'nombre.max'           => 'El nombre no puede pasar de 150 caracteres.',
            'tipo.required'        => 'Selecciona el tipo de organización.',
            'tipo.in'              => 'El tipo seleccionado no es válido.',
            'telefono.required'    => 'El teléfono es obligatorio.',
            'telefono.digits'      => 'El teléfono debe tener 10 dígitos.',
            'direccion.required'   => 'La dirección es obligatoria.',
            'direccion.max'        => 'La dirección no puede pasar de 225 caracteres.',
            'colonia.max'          => 'La colonia no puede pasar de 100 caracteres.',
            'ciudad.required'      => 'La ciudad es obligatoria.',
            'ciudad.max'           => 'La ciudad no puede pasar de 100 caracteres.',
            'codigo_postal.digits' => 'El código postal debe tener 5 dígitos.',
        ]);

        // Nombre único sin importar mayúsculas/minúsculas.
        $validator->after(function ($validator) use ($request, $ignorarId) {
            $nombre = addcslashes($request->input('nombre'), '%_\\');

            $repetido = Organizacion::query()
                ->where('nombre', 'ilike', $nombre)
                ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
                ->exists();

            if ($repetido) {
                $validator->errors()->add('nombre', 'Ya existe una organización con ese nombre.');
            }
        });

        return $validator->validate();
    }

    /** Crea los casilleros A-01 ... A-06 (igual que en el registro). */
    private function crearCasilleros(int $organizacionId): void
    {
        for ($i = 1; $i <= self::CASILLEROS_POR_ORGANIZACION; $i++) {
            Casillero::create([
                'organizaciones_id' => $organizacionId,
                'numero'            => 'A-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'estado'            => self::ESTADO_CASILLERO_INICIAL,
            ]);
        }
    }

    private function formatear(Organizacion $org): array
    {
        return [
            'id'            => $org->id,
            'codigo'        => $org->codigo,
            'nombre'        => $org->nombre,
            'tipo'          => $org->tipo,
            'telefono'      => $org->telefono,
            'direccion'     => $org->direccion,
            'colonia'       => $org->colonia,
            'ciudad'        => $org->ciudad,
            'codigo_postal' => $org->codigo_postal,
            'estado'        => $org->estado,
            'casilleros'    => $org->casilleros_count ?? 0,
        ];
    }
}