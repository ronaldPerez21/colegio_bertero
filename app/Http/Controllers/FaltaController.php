<?php

namespace App\Http\Controllers;

use App\Models\Docente;
use App\Models\Falta;
use App\Models\Persona;
use App\Rules\IsDocenteUniqueData;
use App\Rules\PersonaUniqueData;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FaltaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $faltas = Falta::select("*")->cursorPaginate(5);

        if(Auth::user()->roles->contains("nombre", "Docente")){
            $faltas = Auth::user()->docente->faltas()->cursorPaginate(5);
        }

        return view("registros.faltas.index", compact("faltas"));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view("registros.faltas.create");
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate(
            [ 
                'ci' => [ 'required', 'numeric', new PersonaUniqueData() ],
                'motivo' => 'required',
            ],
            [ 
                'ci.required' => 'El campo ci es requerido.',
                'ci.numeric' => 'El campo ci debe ser entero.',

                'motivo.required' => 'El campo motivo es requerido.' 
            ]
        );

        $falta = new Falta();
        $persona = Persona::where('ci', $request->ci)->get()[0];
        $fechaActual = Carbon::now();

        $falta->motivo = $request->motivo;
        $falta->fecha = $fechaActual;
        $falta->docentes_id = $persona->id;
        $falta->save();
        Http::post('https://colegio-bi-microservicio.azurewebsites.net/api/falta', [
            "motivo" => $request->motivo,
            "ci" => $persona->ci
        ]);
        return redirect()->route("faltas.index")->with("info", "Nueva falta agregado!!!");
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $falta = Falta::find($id);
        return view("registros.faltas.edit", compact("falta"));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $falta = Falta::find($id);
        $falta->motivo = $request->motivo;
        $falta->update();

        return redirect()->route("faltas.index")->with("info", "falta actualizado!!!"); 
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $falta = Falta::find($id);
        $falta->delete();

        return redirect()->route("faltas.index")->with("info", "falta eliminado!!!"); 
    }

    public function buscarFaltas(Request $request)
    {
        $searchTerm = $request->input('query');       
        $personasSearch = Persona::where('ci', $searchTerm)->get();

        $faltas = [];
        if(count($personasSearch) === 1) {
            $docente = Docente::where('personas_id', $personasSearch[0]->id)->get();
            if(count($docente) === 1)
                $faltas = $docente[0]->faltas;
        }

        $result = [];
        foreach($faltas as $falta) {
            array_push($result, [
                "id" => $falta->id,
                "motivo" => $falta->motivo,
                "fecha" => $falta->fecha,
                "docentes_id" => $falta->docentes_id,
                "ci" => $personasSearch[0]->ci,
                "docente" => $personasSearch[0]->nombres.''.$personasSearch[0]->apellidos,
            ]);
        }

        return response()->json($result);
    }
}
