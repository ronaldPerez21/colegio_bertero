<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePersonaRequest;
use App\Http\Requests\UpdatePersonaRequest;
use App\Models\Docente;
use App\Models\Grado;
use App\Models\GradoMateria;
use App\Models\JuntaEscolar;
use App\Models\Materia;
use App\Models\Persona;
use App\Models\PersonaRole;
use App\Models\Role;
use App\Rules\UpdatePersonaUniqueData;
use Faker\Provider\ar_EG\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpParser\Node\Expr\Cast\Object_;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class PersonaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {   
        // return Persona::all();
        // $docente = Docente::findOrFail(13);
        // $persona = $docente->persona;

        // $persona = Persona::find(16);
        // $rolesPersonas = $persona->roles;
        // return $rolesPersonas;
        // $role = Role::find(1);
        // $personasConRole = $role->personas;

        // $role = Role::find(1);
        // $permisosRole = $role->permisos;

        // $juntaEscolar = JuntaEscolar::find(1);
        // $juntaEscolarGrado = $juntaEscolar->grados;

        // $gradosMaterias = GradoMateria::find(1);
        // $materia = $gradosMaterias->materia;
        // $grado = $gradosMaterias->grado;

        // $gradosMaterias = GradoMateria::find(1);
        // $horarios = $gradosMaterias->horarios;

        $personasPag = Persona::select("*")->cursorPaginate(5);


        $personas = [];
        for($i = 0; $i < count($personasPag); $i++){
            $roleActivo = $this->getRoleActivo($personasPag[$i]);
            array_push(
                $personas,
                [
                    "persona" => $personasPag[$i],
                    "role" => $roleActivo
                ]
            );
        }
        // return $personas;
        return view("usuarios.personas.index", compact("personasPag", "personas"));
    }

    public function getRoleActivo($persona) {
        $roleActivo = (object)[];
        //Buscar role activo
        foreach($persona->roles as $role){
            if($role->pivot->estado_activo == true){
                $roleActivo = [
                        "id" => $role->id,
                        "nombre" => $role->nombre,
                        "estado_activo" => $role->pivot->estado_activo
                ];
                break;
            }
        }
        return $roleActivo;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $roles = Role::all();

        return view("usuarios.personas.create", compact("roles"));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'photo' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        $request["password"] = bcrypt($request->password);
        //Creación de la persona
        $persona = Persona::create($request->except('photo'));
        $role = Role::find($request->role);
        $uploadedFile = $request->file('photo');
        $path = $uploadedFile->store('imagenes', 's3');
        $url = urldecode(config('filesystems.disks.s3.url') . $path);

        //Creación de la tabla docente, si así lo es
        if(trim(strtolower($role->nombre)) === "docente"){
            $docente = new Docente();
            $docente->personas_id = $persona->id;
            $docente->photo = $url;
            $docente->save();
            $response = Http::post('https://colegio-bi-microservicio.azurewebsites.net/api/docente/create', [
                
                'nombres' => $request->nombres,
                'apellidos' => $request->apellidos,
                'ci' => $request->ci,
            ]);
            
                for ($i = 1; $i <= 5; $i++) {
                    Http::post('https://colegio-bi-microservicio.azurewebsites.net/api/horario-laboral', [
                        
                        'docente' => $persona->ci,
                        'horarioDia' => $i
                    ]
                    );
                }


        }

        //Insertando personas_roles
        $fechaActual = Carbon::now();
        $personaRole = new PersonaRole();
        $personaRole->personas_id = $persona->id;
        $personaRole->roles_id = $role->id;
        $personaRole->estado_activo = ($request->estado_activo == '1') ? true : false;
        $personaRole->fecha_asignacion = $fechaActual;
        $personaRole->save();

        $urlPhoto = $this->getUrlImage($persona->id);
        $dataPerson = [
            "fullname" => $persona->nombres." ". $persona->apellidos,
            "telefono" => $persona->telefono,
            "direccion" => $persona->direccion,
            "sexo" => $persona->sexo,
            "url_photo" => $urlPhoto
        ];

        return redirect()->route("personas.index")->with("info", "Persona creado!!!")->with("data_docente", json_encode($dataPerson));

    }

    public function getUrlImage ($id) {
        $docente = new Docente();
        $docenteResult = $docente->select("*")->where('personas_id', $id)->get();

        //$urlBucket = "https://fotosemocion.s3.us-east-1.amazonaws.com/";
        $urlDB = $docenteResult[0]["photo"];
        return $urlDB;
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $persona = Persona::find($id);
        $roles = $persona->roles;
        return view("usuarios.personas.show", compact("persona"));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        // $sql = "select *
        //         from personas
        //         where
        //         LOWER(TRIM(ci)) = ?;
        //         ";
        // $personas = DB::select($sql, ["111"]);

        // return $personas[0]->telefono;

        $persona = Persona::find($id);
        $roles = $persona->roles;
        return view("usuarios.personas.edit", compact("persona"));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePersonaRequest $request, string $id)
    {

        //Actualizar persona
        $persona = Persona::find($id);
        $persona->update($request->all());

        //Actualizar personas_roles
        $personas_id = $persona->id;
        $role = Role::find($request->role);
        
        $sql = "UPDATE personas_roles SET estado_activo = ? WHERE personas_id = ? and roles_id = ?";
        DB::select($sql, [ ($request->estado_activo == '1') ? true : false, $personas_id, $role->id ]);
        // return $role;

        return redirect()->route("personas.index")->with("info", "Persona con ci: $persona->ci actualizado!!!");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $persona = Persona::find($id);

        $names = "$persona->nombres $persona->apellidos";
        $persona->delete();
        return redirect()->route("personas.index")->with("info", "$names eliminado!!!");
    }

    public function buscar(Request $request)
    {
        $searchTerm = $request->input('query');       
        $personasSearch = Persona::where('nombres', 'LIKE', "%$searchTerm%")
                        ->orWhere('apellidos', 'LIKE', "%$searchTerm%")
                        ->get();

        $personas = [];
        for($i = 0; $i < count($personasSearch); $i++){
            $roleActivo = $this->getRoleActivo($personasSearch[$i]);
            array_push(
                $personas,
                [
                    "persona" => $personasSearch[$i],
                    "role" => $roleActivo
                ]
            );
        }
        return response()->json($personas);
    }

    public function buscarForCI(Request $request)
    {
        $searchTerm = $request->input('query');       
        $personasSearch = Persona::where('ci', $searchTerm)->get();

        return response()->json($personasSearch);
    }

    public function createRole(Persona $persona) {
        $roles = Role::all();
        return view("usuarios.personas.asignar-role", compact("persona", "roles"));
    }

    public function storeRole(Request $request, Persona $persona) {

        //Insertando personas_roles
        $fechaActual = Carbon::now();
        $personaRole = new PersonaRole();
        $personaRole->personas_id = $persona->id;
        $personaRole->roles_id = $request->role;
        $personaRole->estado_activo = ($request->estado_activo == '1') ? true : false;
        $personaRole->fecha_asignacion = $fechaActual;
        $personaRole->save();

        return redirect()->route("personas.show", $persona->id)->with("info", "Nuevo role agregado!!!");
    }
}
