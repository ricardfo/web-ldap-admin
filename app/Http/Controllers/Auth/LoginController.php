<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Socialite;
use App\User;
use App\Config;
use Auth;
use Illuminate\Http\Request;
use App\Ldap\User as LdapUser;

use Uspdev\Replicado\Pessoa;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function redirectToProvider()
    {
        return Socialite::driver('senhaunica')->redirect();
    }

    public function handleProviderCallback(Request $request)
    {
        $userSenhaUnica = Socialite::driver('senhaunica')->user();

        // verificar se há vínculo com a unidade
        $tem_vinculo = false;
        foreach($userSenhaUnica->vinculo as $vinculo) {
            if($vinculo['codigoUnidade'] == trim(config('web-ldap-admin.replicado_unidade'))) {
                $tem_vinculo = true;
            }
        }

        // Se tem vínculo cadastra no DC e no laravel        
        if($tem_vinculo) {

            # Cadastro do usuário no laravel
            $user = User::where('username',$userSenhaUnica->codpes)->first();
            if (is_null($user) or $user == false) $user = new User;
                       
            // bind do dados retornados
            $user->username = $userSenhaUnica->codpes;
            $user->email = $userSenhaUnica->email;
            $user->name = Pessoa::nomeCompleto($userSenhaUnica->codpes);
            $user->save();

            # Cadastro do usuário no DC
            foreach($userSenhaUnica->vinculo as $vinculo) {
                $setor = str_replace('-' . config('web-ldap-admin.replicado_unidade'), '', $vinculo['nomeAbreviadoSetor']);
                /* Error: Se a pessoa não pertence a nenhum setor cai nesse if, mas $pessoa não foi definido...
                if (empty($setor)) {
                    $setor = $pessoa['tipvinext'];
                }
                */
                if($vinculo['codigoUnidade'] == trim(config('web-ldap-admin.replicado_unidade'))) {
                    $attr = [
                        'nome'  => $user->name,
                        'email' => $user->email,
                        'setor' => ucfirst(strtolower(str_replace('-' . config('web-ldap-admin.replicado_unidade'), '', $vinculo['tipoVinculo']))) . ' ' . $setor 
                    ];
                    $groups = [
                        str_replace('-' . config('web-ldap-admin.replicado_unidade'), '', $vinculo['nomeAbreviadoSetor']), 
                        ucfirst(strtolower(str_replace('-' . config('web-ldap-admin.replicado_unidade'), '', $vinculo['tipoVinculo']))),
                        ucfirst(strtolower(str_replace('-' . config('web-ldap-admin.replicado_unidade'), '', $vinculo['tipoVinculo']))) . ' ' .
                        str_replace('-' . config('web-ldap-admin.replicado_unidade'), '', $vinculo['nomeAbreviadoSetor'])      
                    ];
                    sort($groups);
                    LdapUser::createOrUpdate($user->username,$attr,$groups);
                }
            }           
            
            Auth::login($user, true);
            return redirect('/');
        }
        else {

            // Usuários sem vínculos com a unidade
            $config = Config::all()->last();
            if($config != null) {
                $codpes_sem_vinculo = explode(',',$config->codpes_sem_vinculo);
                if(in_array($userSenhaUnica->codpes,$codpes_sem_vinculo)) {
                    $user = User::where('username',$userSenhaUnica->codpes)->first();
                    if (is_null($user) or $user == false) $user = new User;
                    $user->username = $userSenhaUnica->codpes;
                    $user->email = $userSenhaUnica->email;
                    $user->name = $userSenhaUnica->nompes;
                    $user->save();
                    $attr = [
                        'nome'  => $user->name,
                        'email' => $user->email,
                    ];
                    LdapUser::createOrUpdate($userSenhaUnica->codpes,$attr,['SEMVINCULOUNIDADE']);
                    Auth::login($user, true);
                }
            } else {
                $request->session()->flash('alert-danger', 'Usuário sem acesso ao sistema.');
            }
            return redirect('/');
        }
    }

    public function logout(Request $request) {
        Auth::logout();
        return redirect('/');
    }

}
