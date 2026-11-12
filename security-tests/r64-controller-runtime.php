<?php
declare(strict_types=1);

namespace Fleetbase\FleetOps\Support {
    class Utils { public static function castBoolean($x): bool { return (bool) $x; } }
    class NavigatorLinkTokenService {
        public array $issued=[];
        public array $usable=[];
        public function issue(\Fleetbase\Models\User $issuer): string {
            $this->issued[]=$issuer->company_uuid;
            $token=str_repeat('a',64);
            $this->usable[$token]=$issuer;
            return $token;
        }
        public function redeemWith(string $token, \Closure $callback): mixed {
            $issuer=$this->usable[$token]??null;
            if (!$issuer) return null;
            $value=$callback($issuer);
            unset($this->usable[$token]);
            return $value;
        }
    }
    class NavigatorCredentialService {}
}
namespace Fleetbase\Support { class NavigatorCredentialScope { public static function valid($c): bool {return $c->valid;} } }
namespace Fleetbase\FleetOps\Traits { trait AuthorizesMethods { public function authorizeMethods(array $methods): void {} } }
namespace Fleetbase\Http\Controllers { class Controller {} }
namespace Fleetbase\Http\Resources { class Organization {} }
namespace Fleetbase\Models {
    class User { public function __construct(public string $company_uuid='companyA', public string $type='admin', public string $status='active') {} }
    class ApiCredential { public function __construct(public string $key='FAKE_CREDENTIAL_ONLY',public bool $valid=true) {} }
    class Company {}
    class Setting {}
}
namespace Illuminate\Http {
    class Request {
        public function __construct(private array $query=[],private array $payload=[],private bool $json=true) {}
        public function query(): array {return $this->query;}
        public function isJson(): bool {return $this->json;}
        public function json(string $key): mixed {return $this->payload[$key]??null;}
    }
}
namespace Illuminate\Support\Facades { class Redirect {} class DB {} }
namespace Illuminate\Support { class Str {} }
namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1 {
    class FakeResponse {
        public array $headers=[];
        public function __construct(public int $status,public array $body) {}
        public function header(string $name,string $value): self {$this->headers[$name]=$value; return $this;}
    }
    class FakeResponseFactory {
        public function error(string $error,int $code=400): FakeResponse {return new FakeResponse($code,['error'=>$error]);}
        public function json(array $body): FakeResponse {return new FakeResponse(200,$body);}
    }
    function response(): FakeResponseFactory {return new FakeResponseFactory();}
    require __DIR__ . '/../server/src/Http/Controllers/Internal/v1/NavigatorController.php';
    class Probe extends NavigatorController {
        public bool $enabled=false;
        public ?\Fleetbase\Models\User $issuer=null;
        public ?\Fleetbase\Models\ApiCredential $credential=null;
        public \Fleetbase\FleetOps\Support\NavigatorLinkTokenService $tokens;
        public int $lookups=0;
        public function __construct() {$this->tokens = new \Fleetbase\FleetOps\Support\NavigatorLinkTokenService();}
        protected function navigatorV2Enabled(): bool {return $this->enabled;}
        protected function navigatorLinkIssuer(): ?\Fleetbase\Models\User {return $this->issuer;}
        protected function navigatorLinkTokens(): \Fleetbase\FleetOps\Support\NavigatorLinkTokenService {return $this->tokens;}
        protected function firstOrCreateNavigatorCredential(\Fleetbase\Models\User $u): \Fleetbase\Models\ApiCredential {$this->lookups++; return $this->credential ?? new \Fleetbase\Models\ApiCredential();}
        protected function validNavigatorCredential(\Fleetbase\Models\ApiCredential $c): bool {return $c->valid;}
        protected function secureRootUrl(): string {return 'https://synthetic.invalid';}
        protected function socketClusterHost(): string {return 'socket.synthetic.invalid';}
        protected function socketClusterPort(): int|string {return 8000;}
        protected function socketClusterSecure(): bool {return true;}
    }
}
namespace {
    function check(bool $ok,string $name): void {
        if (!$ok) throw new \RuntimeException($name);
        echo "R64_RUNTIME_".$name."=PASS\n";
    }
    $p=new \Fleetbase\FleetOps\Http\Controllers\Internal\v1\Probe();
    $req=new \Illuminate\Http\Request();
    check($p->linkApp($req)->status===403,'legacy_GET_refused');
    check($p->getLinkAppUrl()->status===403,'legacy_issue_GET_refused');
    check($p->issuePairing($req)->status===403,'flag_off_issue_refused');
    check($p->redeemPairing($req)->status===403,'flag_off_redeem_refused');
    $p->enabled=true;
    check($p->issuePairing($req)->status===403,'anonymous_issue_refused');
    $p->issuer=new \Fleetbase\Models\User();
    $issued=$p->issuePairing($req);
    check($issued->status===200 && strlen($issued->body['pairingSecret'])===64 && $issued->body['expiresInSeconds']===300,'authorized_issue_300_seconds');
    check(!isset($issued->body['linkUrl']) && ($issued->headers['Cache-Control']??'')==='no-store','issue_no_linkurl_no_store');
    $key=$issued->body['pairingSecret'];
    check($p->redeemPairing(new \Illuminate\Http\Request(['x'=>'query'],['pairingSecret'=>$key]))->status===403,'query_refused');
    check($p->redeemPairing(new \Illuminate\Http\Request([],['pairingSecret'=>$key],false))->status===403,'non_json_refused');
    check($p->redeemPairing(new \Illuminate\Http\Request([],['pairingSecret'=>'bad']))->status===403,'malformed_refused');
    $ok=$p->redeemPairing(new \Illuminate\Http\Request([],['pairingSecret'=>$key]));
    check($ok->status===200 && $ok->body['credential']==='FAKE_CREDENTIAL_ONLY','redeem_body_only');
    check(!isset($ok->headers['Location']) && ($ok->headers['Cache-Control']??'')==='no-store','no_location_no_store');
    check($p->redeemPairing(new \Illuminate\Http\Request([],['pairingSecret'=>$key]))->status===403,'replay_refused');
    $p->issuePairing($req);
    $p->credential=new \Fleetbase\Models\ApiCredential('FAKE_BAD_CREDENTIAL',false);
    $bad=$p->redeemPairing(new \Illuminate\Http\Request([],['pairingSecret'=>$key]));
    check($bad->status===503,'invalid_credential_fails_closed');
    echo "R64_RUNTIME_CONTROLLER_CHECKS=14/14\n";
}
