import * as DD from './data.js';
export async function boot(rootEl){
DD.setBase(rootEl.dataset.base||'/ubicatec/');
const stage=rootEl.querySelector('three-d-stage');
const [{THREE},D]=await Promise.all([stage.ready,DD.load(DD.readServer())]);
// La página pudo cambiar mientras cargaban los datos (wire:navigate): no se arma nada sobre un nodo ya desconectado.
if(!rootEl.isConnected||stage._disposed)return null;
const {KINDS,KIND_COLORS,KIND_GLYPH,norm}=DD;const AREAS=D.AREAS;
const ac=new AbortController(),sig={signal:ac.signal};let alive=true,rafId=0;
{const s=document.createElement('style');s.textContent='.note{display:none!important}.toolbar{display:none!important;left:auto!important;right:16px!important;bottom:16px!important;top:auto!important;transform:none!important;opacity:.85}@media (max-width:900px){.toolbar{display:none!important}}';stage.shadowRoot.appendChild(s);}
const camera=stage._camera,controls=stage._controls,scene=stage._scene;
const $=s=>rootEl.querySelector(s);
const esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const MOB=()=>rootEl.clientWidth<=900;
// Texto sobre un color de departamento: blanco si da 4.5:1 (WCAG), si no, tinta oscura (pulido 1.32).
const onC=c=>{const m=/^#([0-9a-f]{6})$/i.exec(c||'');if(!m)return'#fff';const v=[0,2,4].map(i=>{const x=parseInt(m[1].slice(i,i+2),16)/255;return x<=.03928?x/12.92:((x+.055)/1.055)**2.4});const L=.2126*v[0]+.7152*v[1]+.0722*v[2];return 1.05/(L+.05)>=4.5?'#fff':'#0b1a26'};
const FH=6;

// ---------- índices ----------
const B=Object.fromEntries(D.BUILDINGS.map(b=>[b.id,b]));
const SP=Object.fromEntries(D.SPACES.map(s=>[s.id,s]));
const SU=Object.fromEntries(D.SUBJECTS.map(s=>[s.id,s]));
const GR=Object.fromEntries(D.GROUPS.map(g=>[g.id,g]));
const PR=Object.fromEntries(D.PROFS.map(p=>[p.id,p]));
const DP=Object.fromEntries(D.DEPTS.map(d=>[d.id,d]));
const by=k=>{const m={};D.CLASSES.forEach(c=>(m[c[k]]??=[]).push(c));return m};
const byRoom=by('room'),byProf=by('prof'),byGroup=by('group'),bySubj=by('subject');
const spacesOf=(bid,fl)=>D.SPACES.filter(s=>s.building===bid&&(fl==null||s.floor===fl));
const pName=p=>p.title?`${p.title} ${p.name}`:p.name;
const pShort=pName;
const AN=a=>AREAS[a]?.short||'Sin departamento',AL=a=>AREAS[a]?.alias||'',AB=a=>AREAS[a]?.abbr||'·';
const NOROOM='Sin salón en el mapa';
const rName=c=>c.room&&SP[c.room]?SP[c.room].name:(c.roomLabel?(/^(aula|lab|sal)/i.test(c.roomLabel)?c.roomLabel:'Aula '+c.roomLabel):NOROOM);
const rRaw=c=>c.room&&SP[c.room]?SP[c.room].raw:(c.roomLabel||'—');
const rBld=c=>c.room&&SP[c.room]?B[SP[c.room].building]:null;
const pOf=c=>c.prof&&PR[c.prof]?pShort(PR[c.prof]):'Sin docente asignado';
const gOf=c=>GR[c.group]?'Grupo '+GR[c.group].code:c.group;
const sOf=c=>SU[c.subject]?.name||c.subject;
const hrs=c=>`${c.hour}:00–${c.end}:00`;
const dur=c=>c.end-c.hour;
const AC=a=>AREAS[a]?.color||'#6E2234';
const bOfRoom=rid=>SP[rid]?.building||null;
const areaB={};for(const a in AREAS)areaB[a]=new Set();
D.BUILDINGS.forEach(b=>b.areas.forEach(a=>areaB[a]?.add(b.id)));D.SPACES.forEach(s=>s.owner&&areaB[s.owner]?.add(s.building));D.DEPTS.forEach(d=>areaB[d.area]?.add(d.b));D.CLASSES.forEach(c=>{const a=SU[c.subject]?.area,b=bOfRoom(c.room);if(a&&b)areaB[a].add(b)});
const roomLabel=s=>s.name;

// ---------- estado / tiempo ----------
const S={q:'',areas:new Set(),stack:[],view:'3d',occ:false,expanded:{},route:true,open:null,floor:1,origin:D.START.slice()};
const T0=D.HOURS[0],T1=D.HOURS[D.HOURS.length-1];
{const n=new Date();let d=n.getDay()-1,h=n.getHours();if(d<0||d>=D.DAYS.length||h<T0||h>T1){d=Math.min(2,D.DAYS.length-1);h=10}S.day=d;S.hour=h;}
const liveAt=c=>c.days.includes(S.day)&&S.hour>=c.hour&&S.hour<c.end;
const nowIn=rid=>rid?(byRoom[rid]||[]).find(liveAt):undefined;

// ---------- escena ----------
const mat=(name,color,o={})=>new THREE.MeshStandardMaterial({name,color,roughness:o.r??.9,metalness:0,...(o.x||{})});
const MT={base:mat('terreno','#DCD5C8'),borde:mat('banqueta','#EAE4DA'),estac:mat('estacionamiento','#A9ACB0'),cancha:mat('cancha','#78AE62'),linea:mat('linea','#F4F1EA'),vidrio:mat('vidrio','#2D3138',{r:.3}),azotea:mat('azotea','#E2D9CC'),copa:mat('copa','#4E8A3D'),tronco:mat('tronco','#7A5A3E'),losa:mat('losa','#F3EEE7')};
const root=new THREE.Group();root.name='campus_itcj';
const shapeOf=(poly,s=1,c)=>{const sh=new THREE.Shape();poly.forEach(([x,z],i)=>{const px=c?c[0]+(x-c[0])*s:x,pz=c?c[1]+(z-c[1])*s:z;i?sh.lineTo(px,-pz):sh.moveTo(px,-pz)});return sh};
const offsetPoly=(poly,d)=>{const n=poly.length;let area=0;for(let i=0;i<n;i++){const a=poly[i],b=poly[(i+1)%n];area+=a[0]*b[1]-b[0]*a[1]}const sg=area>0?1:-1;
  return poly.map((p,i)=>{const a=poly[(i-1+n)%n],b=poly[(i+1)%n];const e1=[p[0]-a[0],p[1]-a[1]],e2=[b[0]-p[0],b[1]-p[1]];const l1=Math.hypot(...e1)||1,l2=Math.hypot(...e2)||1;
    const n1=[e1[1]/l1*sg,-e1[0]/l1*sg],n2=[e2[1]/l2*sg,-e2[0]/l2*sg];let m=[n1[0]+n2[0],n1[1]+n2[1]];const ml=Math.hypot(...m)||1;m=[m[0]/ml,m[1]/ml];const k=Math.min(3,1/Math.max(.35,(m[0]*n1[0]+m[1]*n1[1])));return[p[0]-m[0]*d*k,p[1]-m[1]*d*k]})};
function extrude(poly,h,m,y,name,parent=root){const g=new THREE.ExtrudeGeometry(shapeOf(poly),{depth:h,bevelEnabled:false});const me=new THREE.Mesh(g,m);me.rotation.x=-Math.PI/2;me.position.y=y;me.name=name;parent.add(me);return me}
// terreno: envolvente convexa + margen
const allPts=[...D.BUILDINGS.flatMap(b=>b.poly),...D.GROUNDS.flatMap(g=>g.poly)];
function hull(pts){pts=pts.slice().sort((a,b)=>a[0]-b[0]||a[1]-b[1]);const cr=(o,a,b)=>(a[0]-o[0])*(b[1]-o[1])-(a[1]-o[1])*(b[0]-o[0]);const lo=[],up=[];for(const p of pts){while(lo.length>=2&&cr(lo[lo.length-2],lo[lo.length-1],p)<=0)lo.pop();lo.push(p)}for(const p of pts.reverse()){while(up.length>=2&&cr(up[up.length-2],up[up.length-1],p)<=0)up.pop();up.push(p)}return lo.slice(0,-1).concat(up.slice(0,-1))}
const H0=hull(allPts);const Hc=[H0.reduce((s,p)=>s+p[0],0)/H0.length,H0.reduce((s,p)=>s+p[1],0)/H0.length];
const hullOut=offsetPoly(H0,-18);
extrude(hullOut,1,MT.base,-1,'terreno');extrude(offsetPoly(H0,-21),.98,MT.borde,-1.0,'acera');
MT.pasto=mat('pasto','#86B46E');MT.pasto2=mat('pasto_oscuro','#76A55F');MT.pista=mat('pista','#C86F58');MT.duela=mat('cancha_basquet','#6F86A8');MT.llave=mat('zona_basquet','#8E3B45');MT.tierra=mat('tierra','#C49A6C');MT.camino=mat('andador','#A6A39D');MT.guarnicion=mat('guarnicion','#E4DED4');MT.endzone=mat('zona_anotacion','#5E8F4A');
D.GREENS.forEach((p,i)=>extrude(p,.02,MT.pasto,0,'area_verde_'+i));
// andadores: trazados del plano impreso, vectorizados a coordenadas reales
function mergeGeoms(list){let n=0;const g2=list.map(g=>{const q=g.index?g.toNonIndexed():g;n+=q.attributes.position.count;return q});const pos=new Float32Array(n*3),nor=new Float32Array(n*3);let o=0;g2.forEach(q=>{pos.set(q.attributes.position.array,o*3);nor.set(q.attributes.normal.array,o*3);o+=q.attributes.position.count});const G=new THREE.BufferGeometry();G.setAttribute('position',new THREE.BufferAttribute(pos,3));G.setAttribute('normal',new THREE.BufferAttribute(nor,3));return G}
{const parts=[],edge=[];const seg=(p1,p2,w,y,arr)=>{const dx=p2[0]-p1[0],dz=p2[1]-p1[1],L=Math.hypot(dx,dz);if(L<.05)return;const g=new THREE.BoxGeometry(L,.06,w);g.rotateY(-Math.atan2(dz,dx));g.translate((p1[0]+p2[0])/2,y,(p1[1]+p2[1])/2);arr.push(g)};
  const disc=(p,r,y,arr)=>{const g=new THREE.CylinderGeometry(r,r,.06,20);g.translate(p[0],y,p[1]);arr.push(g)};
  D.WALKS.forEach(e=>{for(let i=1;i<e.p.length;i++){seg(e.p[i-1],e.p[i],e.w,.1,parts);seg(e.p[i-1],e.p[i],e.w+.7,.05,edge)}e.p.forEach(p=>{disc(p,e.w/2,.1,parts);disc(p,e.w/2+.35,.05,edge)})});
  D.PLAZAS.forEach(([x,z,r])=>{disc([x,z],Math.min(r,7),.1,parts)});
  const m1=new THREE.Mesh(mergeGeoms(parts),MT.camino);m1.name='andadores';m1.receiveShadow=true;root.add(m1);
  const m2=new THREE.Mesh(mergeGeoms(edge),MT.guarnicion);m2.name='guarnicion_andadores';m2.receiveShadow=true;root.add(m2);}
D.GROUNDS.filter(g=>g.kind===7).forEach(g=>{extrude(g.poly,.06,MT.estac,0,'estacionamiento_'+norm(g.name).replace(/\W+/g,'_'))});
function strip3(p1,p2,w,m,y,name){const dx=p2[0]-p1[0],dz=p2[1]-p1[1],L=Math.hypot(dx,dz);if(L<.01)return;const me=new THREE.Mesh(new THREE.BoxGeometry(L,.04,w),m);me.position.set((p1[0]+p2[0])/2,y,(p1[1]+p2[1])/2);me.rotation.y=-Math.atan2(dz,dx);me.name=name;root.add(me);return me}
function ringAt(p,r,w,m,y,name,seg=48){const me=new THREE.Mesh(new THREE.RingGeometry(r-w/2,r+w/2,seg),m);me.rotation.x=-Math.PI/2;me.position.set(p[0],y,p[1]);me.name=name;root.add(me)}
function discAt(p,r,m,y,name){const me=new THREE.Mesh(new THREE.CircleGeometry(r,32),m);me.rotation.x=-Math.PI/2;me.position.set(p[0],y,p[1]);me.name=name;root.add(me)}
function obb(poly){const h=hull(poly);let best=null;for(let i=0;i<h.length;i++){const a=h[i],b=h[(i+1)%h.length];const L=Math.hypot(b[0]-a[0],b[1]-a[1]);if(L<1e-6)continue;const u=[(b[0]-a[0])/L,(b[1]-a[1])/L],v=[-u[1],u[0]];let mu=1e9,Mu=-1e9,mv=1e9,Mv=-1e9;for(const p of h){const du=p[0]*u[0]+p[1]*u[1],dv=p[0]*v[0]+p[1]*v[1];mu=Math.min(mu,du);Mu=Math.max(Mu,du);mv=Math.min(mv,dv);Mv=Math.max(Mv,dv)}const ar=(Mu-mu)*(Mv-mv);if(!best||ar<best.ar)best={ar,u,v,cu:(mu+Mu)/2,cv:(mv+Mv)/2,hu:(Mu-mu)/2,hv:(Mv-mv)/2}}
  let {u,v,cu,cv,hu,hv}=best;if(hu<hv){const nu=v,nv=[-u[0],-u[1]];const ncu=cv,ncv=-cu;u=nu;v=nv;cu=ncu;cv=ncv;const t=hu;hu=hv;hv=t}const c=[u[0]*cu+v[0]*cv,u[1]*cu+v[1]*cv];return{c,u,v,hu,hv}}
function rrect(o,ha,hb,r,seg=10){const P=(a,b)=>[o.c[0]+o.u[0]*a+o.v[0]*b,o.c[1]+o.u[1]*a+o.v[1]*b];const pts=[];r=Math.min(r,ha,hb);const cs=[[ha-r,hb-r,0],[-ha+r,hb-r,Math.PI/2],[-ha+r,-hb+r,Math.PI],[ha-r,-hb+r,1.5*Math.PI]];
  for(const [a,b,s] of cs)for(let k=0;k<=seg;k++){const t=s+k/seg*Math.PI/2;pts.push(P(a+Math.cos(t)*r,b+Math.sin(t)*r))}return pts}
function sportField(g){const o=obb(g.poly);const P=(a,b)=>[o.c[0]+o.u[0]*a+o.v[0]*b,o.c[1]+o.u[1]*a+o.v[1]*b];const n=norm(g.name);const hu=o.hu,hv=o.hv;const Y=.13;
  const L=(a0,b0,a1,b1,w=.3)=>strip3(P(a0,b0),P(a1,b1),w,MT.linea,Y,'linea_'+g.id);
  const box=(a0,b0,a1,b1)=>{L(a0,b0,a1,b0);L(a0,b1,a1,b1);L(a0,b0,a0,b1);L(a1,b0,a1,b1)};
  const fill=(a0,b0,a1,b1,m,y,name)=>extrude([P(a0,b0),P(a1,b0),P(a1,b1),P(a0,b1)],.03,m,y,name);
  if(n.includes('basquet')){extrude(g.poly,.1,MT.duela,0,'basquet_'+g.id);const k=Math.max(1,Math.round(hu*2/30));const w=hu*2/k;
    for(let i=0;i<k;i++){const a0=-hu+i*w+1,a1=-hu+(i+1)*w-1,b0=-hv+1,b1=hv-1,am=(a0+a1)/2,hb=(b1-b0)/2;box(a0,b0,a1,b1);L(am,b0,am,b1);ringAt(P(am,0),Math.min(1.8,hb*.25),.25,MT.linea,Y,'circulo');
      const kd=Math.min(5.8,(a1-a0)*.2),kw=Math.min(2.45,hb*.35);fill(a0,-kw,a0+kd,kw,MT.llave,.1,'llave');fill(a1-kd,-kw,a1,kw,MT.llave,.1,'llave')}}
  else if(n.includes('americano')){extrude(g.poly,.08,MT.cancha,0,'americano_'+g.id);const ez=Math.min(9,hu*.1);fill(-hu+1,-hv+1,-hu+1+ez,hv-1,MT.endzone,.08,'zona_anotacion');fill(hu-1-ez,-hv+1,hu-1,hv-1,MT.endzone,.08,'zona_anotacion');
    const st=(2*hu-2-2*ez)/10;for(let i=0;i<10;i++)if(i%2)fill(-hu+1+ez+i*st,-hv+1,-hu+1+ez+(i+1)*st,hv-1,MT.pasto2,.085,'franja');for(let i=0;i<=10;i++)L(-hu+1+ez+i*st,-hv+1,-hu+1+ez+i*st,hv-1,.25);box(-hu+1,-hv+1,hu-1,hv-1)}
  else if(n.includes('baseball')){extrude(g.poly,.08,MT.cancha,0,'baseball_'+g.id);const s=Math.min(1,hv/34);const side=27.4*s,dg=side*Math.SQRT2;const hx=-hu+side*.55;
    const dia=[[hx,0],[hx+dg/2,dg/2],[hx+dg,0],[hx+dg/2,-dg/2]].map(([a,b])=>P(a,b));
    const infield=[];for(let k=0;k<=24;k++){const t=-Math.PI/4+k/24*Math.PI/2;infield.push(P(hx+Math.cos(t)*dg*1.25,Math.sin(t)*dg*1.25))}infield.push(P(hx,0));
    extrude(infield,.03,MT.tierra,.08,'cuadro_interior');extrude(offsetPoly(dia.slice(),-.5),.02,MT.cancha,.11,'pasto_interior');discAt(P(hx+dg*.5,0),2.6*s,MT.tierra,.135,'monticulo');
    dia.forEach((p,i)=>{const b=new THREE.Mesh(new THREE.BoxGeometry(1.2,.12,1.2),MT.linea);b.position.set(p[0],.16,p[1]);b.rotation.y=Math.PI/4;b.name='base_'+i;root.add(b)});
    const fl=Math.min(hv-1,(hu*2-side)*Math.SQRT1_2);L(hx,0,hx+fl,fl,.25);L(hx,0,hx+fl,-fl,.25)}
  else{const tw=7;extrude(rrect(o,hu+tw,hv+tw,hv*.9),.07,MT.pista,0,'pista_atletismo');[2.4,4.8].forEach(dd=>{const sh=shapeOf(rrect(o,hu+dd+.12,hv+dd+.12,hv*.9)),hole=shapeOf(rrect(o,hu+dd-.12,hv+dd-.12,hv*.9));sh.holes.push(hole);const me=new THREE.Mesh(new THREE.ShapeGeometry(sh,4),MT.linea);me.rotation.x=-Math.PI/2;me.position.y=.075;me.name='carril';root.add(me)});
    extrude(rrect(o,hu+.4,hv+.4,hv*.75),.08,MT.cancha,0,'futbol_'+g.id);for(let i=0;i<12;i++)if(i%2)fill(-hu+1+i*(2*hu-2)/12,-hv+1,-hu+1+(i+1)*(2*hu-2)/12,hv-1,MT.pasto2,.08,'franja');
    box(-hu+1,-hv+1,hu-1,hv-1);L(0,-hv+1,0,hv-1);ringAt(P(0,0),Math.min(9.15,hv*.3),.3,MT.linea,Y,'circulo_central');const pd=Math.min(16.5,hu*.16),pw=Math.min(20,hv*.6);box(-hu+1,-pw,-hu+1+pd,pw);box(hu-1-pd,-pw,hu-1,pw);box(-hu+1,-pw*.45,-hu+1+pd*.33,pw*.45);box(hu-1-pd*.33,-pw*.45,hu-1,pw*.45)}}
D.GROUNDS.filter(g=>g.kind===15).forEach(sportField);
{const L={pasto:1,estac:2,guarnicion:3,camino:5,cancha:3,pista:3,duela:3,pasto2:5,endzone:5,tierra:5,llave:5,linea:8};for(const [k,v] of Object.entries(L))if(MT[k]){MT[k].polygonOffset=true;MT[k].polygonOffsetFactor=-v;MT[k].polygonOffsetUnits=-v*2}}

// edificios
const BLD={};const pickB=[];
const RED=new THREE.Color('#B5362C'),PALE=new THREE.Color('#DED6CB'),SPORT=new THREE.Color('#8D9297'),COM=new THREE.Color('#C9783A');
for(const b of D.BUILDINGS){
  const H=b.h??b.floors*FH;const g=new THREE.Group();g.name='edificio_'+norm(b.code);root.add(g);
  const base=b.cat==='Deportes'?SPORT:b.cat==='Comida'?COM:RED;
  const m=mat('muro_'+norm(b.code),base.getHex(),{r:.75});m.transparent=true;
  const shell=extrude(b.poly,H,m,0,b.code+'_cuerpo',g);shell.userData.bid=b.id;pickB.push(shell);
  const cap=extrude(offsetPoly(b.poly,1),.4,MT.azotea,H,b.code+'_azotea',g);
  const bands=[];for(let f=0;f<Math.max(1,Math.round(H/FH));f++){if(f*FH+4.2>H)break;bands.push(extrude(offsetPoly(b.poly,-.12),1.6,MT.vidrio,f*FH+2.6,`${b.code}_ventanas_${f}`,g))}
  let r=0;b.poly.forEach(p=>r=Math.max(r,Math.hypot(p[0]-b.c[0],p[1]-b.c[1])));
  BLD[b.id]={b,g,m,shell,cap,bands,H,base:base.clone(),cur:{col:base.clone(),em:0,op:1},center:new THREE.Vector3(b.c[0],0,b.c[1]),r};
}
// cuadrícula de navegación
const CELL=1.5;let minX=1e9,maxX=-1e9,minZ=1e9,maxZ=-1e9;hullOut.forEach(([x,z])=>{minX=Math.min(minX,x);maxX=Math.max(maxX,x);minZ=Math.min(minZ,z);maxZ=Math.max(maxZ,z)});
const GW=Math.ceil((maxX-minX)/CELL),GH=Math.ceil((maxZ-minZ)/CELL),NN=GW*GH;const grid=new Int16Array(NN),cost=new Uint8Array(NN).fill(10),walk=new Uint8Array(NN);
const inPoly=(p,x,z)=>{let c=false;for(let i=0,j=p.length-1;i<p.length;j=i++){const [xi,zi]=p[i],[xj,zj]=p[j];if(((zi>z)!=(zj>z))&&(x<(xj-xi)*(z-zi)/(zj-zi)+xi))c=!c}return c};
const cx2=i=>minX+(i+.5)*CELL,cz2=j=>minZ+(j+.5)*CELL;
const cellOf=([x,z])=>[Math.max(0,Math.min(GW-1,Math.floor((x-minX)/CELL))),Math.max(0,Math.min(GH-1,Math.floor((z-minZ)/CELL)))];

function eachCell(poly,pad,fn){const xs=poly.map(p=>p[0]),zs=poly.map(p=>p[1]);const i0=Math.max(0,Math.floor((Math.min(...xs)-minX)/CELL)-pad),i1=Math.min(GW-1,Math.ceil((Math.max(...xs)-minX)/CELL)+pad),j0=Math.max(0,Math.floor((Math.min(...zs)-minZ)/CELL)-pad),j1=Math.min(GH-1,Math.ceil((Math.max(...zs)-minZ)/CELL)+pad);for(let j=j0;j<=j1;j++)for(let i=i0;i<=i1;i++)fn(j*GW+i,i,j)}
D.GREENS.forEach(p=>eachCell(p,0,(k,i,j)=>{if(inPoly(p,cx2(i),cz2(j)))cost[k]=30}));
{const mark=(p,r)=>{const [ci,cj]=cellOf(p);const n=Math.ceil(r/CELL);for(let dj=-n;dj<=n;dj++)for(let di=-n;di<=n;di++){const i=ci+di,j=cj+dj;if(i<0||j<0||i>=GW||j>=GH)continue;if(Math.hypot(di,dj)*CELL<=r+.4)cost[j*GW+i]=6}};
  D.WALKS.forEach(e=>{for(let i=1;i<e.p.length;i++){const a=e.p[i-1],b=e.p[i];const L=Math.hypot(b[0]-a[0],b[1]-a[1]);const s=Math.max(1,Math.ceil(L/1));for(let t=0;t<=s;t++)mark([a[0]+(b[0]-a[0])*t/s,a[1]+(b[1]-a[1])*t/s],e.w/2)}});D.PLAZAS.forEach(([x,z,r])=>mark([x,z],Math.min(r,7)))}
D.GROUNDS.forEach(g=>eachCell(g.poly,0,(k,i,j)=>{if(inPoly(g.poly,cx2(i),cz2(j)))cost[k]=g.kind===15?70:11}));
D.BUILDINGS.forEach((b,bi)=>eachCell(b.poly,1,(k,i,j)=>{if(inPoly(b.poly,cx2(i),cz2(j)))grid[k]=bi+1}));
for(let j=0;j<GH;j++)for(let i=0;i<GW;i++)if(!grid[j*GW+i]&&!inPoly(hullOut,cx2(i),cz2(j)))grid[j*GW+i]=-1;
const BI=Object.fromEntries(D.BUILDINGS.map((b,i)=>[b.id,i+1]));
function freeNear([x,z]){let [i,j]=cellOf([x,z]);if(!grid[j*GW+i])return[i,j];for(let r=1;r<40;r++)for(let dj=-r;dj<=r;dj++)for(let di=-r;di<=r;di++){const ni=i+di,nj=j+dj;if(ni>=0&&nj>=0&&ni<GW&&nj<GH&&!grid[nj*GW+ni])return[ni,nj]}return[i,j]}
function nearestOnPoly(poly,p){let best=null,bd=1e9,bn=null;for(let i=0;i<poly.length;i++){const a=poly[i],b=poly[(i+1)%poly.length];const dx=b[0]-a[0],dz=b[1]-a[1],L2=dx*dx+dz*dz||1;let t=((p[0]-a[0])*dx+(p[1]-a[1])*dz)/L2;t=Math.max(0,Math.min(1,t));const q=[a[0]+dx*t,a[1]+dz*t];const d=Math.hypot(q[0]-p[0],q[1]-p[1]);if(d<bd){bd=d;best=q;const L=Math.sqrt(L2);bn=[-dz/L,dx/L]}}
  if(inPoly(poly,best[0]+bn[0]*.6,best[1]+bn[1]*.6))bn=[-bn[0],-bn[1]];return{p:best,out:bn}}
// accesos: declarados en el repo + estimados
const DOORS=[];const DOORS_OF={};
const mkDoor=(b,c,declared,name)=>{const {p,out}=nearestOnPoly(b.poly,c);const [ci,cj]=freeNear([p[0]+out[0]*2.2,p[1]+out[1]*2.2]);const dd={id:'A'+DOORS.length,b:b.id,p,out,inP:[p[0]-out[0]*.8,p[1]-out[1]*.8],cell:cj*GW+ci,declared,name};DOORS.push(dd);(DOORS_OF[b.id]??=[]).push(dd)};
D.DOORS.forEach(d=>{const b=B[d.b];if(b)mkDoor(b,d.c,d.declared,d.declared?d.name:'Acceso estimado')});
D.BUILDINGS.forEach(b=>{if(!DOORS_OF[b.id])mkDoor(b,Hc,false,'Acceso estimado')});
const dirName=o=>{const a=Math.atan2(o[0],-o[1])*180/Math.PI;return['norte','noreste','este','sureste','sur','suroeste','oeste','noroeste'][Math.round(((a+360)%360)/45)%8]};
function heap(){const h=[];return{h,push(k,f){h.push([f,k]);let i=h.length-1;while(i>0){const p=(i-1)>>1;if(h[p][0]<=h[i][0])break;const t=h[p];h[p]=h[i];h[i]=t;i=p}},pop(){const t=h[0],l=h.pop();if(h.length){h[0]=l;let i=0;for(;;){let a=2*i+1,b=a+1,m=i;if(a<h.length&&h[a][0]<h[m][0])m=a;if(b<h.length&&h[b][0]<h[m][0])m=b;if(m===i)break;const q=h[m];h[m]=h[i];h[i]=q;i=m}}return t}}}
let NET_DONE=false;const stepCost=k=>walk[k]?(NET_DONE?.55:.35):cost[k]/10;
function gsearch(start,isGoal){const g=new Float32Array(NN).fill(Infinity),prev=new Int32Array(NN).fill(-1),cl=new Uint8Array(NN);const H=heap();g[start]=0;H.push(start,0);
  while(H.h.length){const [,k]=H.pop();if(cl[k])continue;cl[k]=1;if(isGoal&&isGoal(k)){const p=[];let c=k;while(c>=0){p.push(c);c=prev[c]}return{path:p.reverse(),g,prev}}
    const i=k%GW,j=(k/GW)|0;for(let dj=-1;dj<=1;dj++)for(let di=-1;di<=1;di++){if(!di&&!dj)continue;const ni=i+di,nj=j+dj;if(ni<0||nj<0||ni>=GW||nj>=GH)continue;const nk=nj*GW+ni;if(grid[nk]!==0)continue;if(di&&dj&&(grid[j*GW+ni]||grid[nj*GW+i]))continue;
      const ng=g[k]+stepCost(nk)*(di&&dj?1.4142:1);if(ng<g[nk]){g[nk]=ng;prev[nk]=k;H.push(nk,ng)}}}return{path:null,g,prev}}
const los=(a,b)=>{let x0=a%GW,y0=(a/GW)|0;const x1=b%GW,y1=(b/GW)|0;const dx=Math.abs(x1-x0),dy=Math.abs(y1-y0),sx=x0<x1?1:-1,sy=y0<y1?1:-1;let e=dx-dy;const c0=cost[a];for(;;){const k=y0*GW+x0;if(grid[k]>0||(Math.abs(cost[k]-c0)>12&&!walk[k]))return false;if(x0===x1&&y0===y1)return true;const e2=2*e;if(e2>-dy){e-=dy;x0+=sx}if(e2<dx){e+=dx;y0+=sy}}};
const simplify=path=>{if(path.length<3)return path.slice();const s=[path[0]];let a=0;for(let k=2;k<path.length;k++)if(!los(path[a],path[k])){s.push(path[k-1]);a=k-1}s.push(path[path.length-1]);return s};
// conectores de acceso al pavimento
{NET_DONE=true;}
// árboles en áreas verdes
{let s=11;const rr=()=>(s=(s*16807)%2147483647)/2147483647;const tg=new THREE.CylinderGeometry(.35,.5,3,6),cg=new THREE.IcosahedronGeometry(2.8,1);let n=0;
  const blocked=(x,z,d)=>{const [ci,cj]=cellOf([x,z]);for(let dj=-d;dj<=d;dj++)for(let di=-d;di<=d;di++){const i=ci+di,j=cj+dj;if(i<0||j<0||i>=GW||j>=GH)return true;const k=j*GW+i;if(grid[k]||cost[k]!==30)return true}return false};
  for(let k=0;k<14000&&n<380;k++){const x=minX+rr()*(maxX-minX),z=minZ+rr()*(maxZ-minZ);const [ci,cj]=cellOf([x,z]);const green=cost[cj*GW+ci]===30;if(!green)continue;if(blocked(x,z,2))continue;
    if(green&&Math.sin(x*.05)+Math.cos(z*.045)+Math.sin((x-z)*.03)<-.4)continue;const sc=.7+rr()*.7;
    const t=new THREE.Mesh(tg,MT.tronco);t.position.set(x,1.5,z);t.name='arbol_tronco_'+n;const c=new THREE.Mesh(cg,MT.copa);c.position.set(x,3+2.4*sc,z);c.scale.setScalar(sc);c.name='arbol_copa_'+n;root.add(t,c);n++}}

stage.setObject(root);
camera.near=1;camera.far=5000;camera.updateProjectionMatrix();
controls.maxPolarAngle=Math.PI*.46;controls.minDistance=25;controls.maxDistance=1200;controls.screenSpacePanning=false;
const HOME={pos:new THREE.Vector3(Hc[0]-120,330,Hc[1]+360),tgt:new THREE.Vector3(Hc[0]-110,0,Hc[1]+20)};
const HOME_M={pos:new THREE.Vector3(Hc[0],620,Hc[1]+520),tgt:new THREE.Vector3(Hc[0],0,Hc[1]+90)};
const home=()=>MOB()?HOME_M:HOME;camera.position.copy(home().pos);controls.target.copy(home().tgt);controls.update();
{const k=stage._key;k.position.set(Hc[0]+250,500,Hc[1]+200);k.target.position.set(Hc[0],0,Hc[1]);scene.add(k.target);const sp=380;Object.assign(k.shadow.camera,{left:-sp,right:sp,top:sp,bottom:-sp,near:10,far:1500});k.shadow.camera.updateProjectionMatrix();k.shadow.mapSize.set(4096,4096);
  // three.js crea el mapa de sombras una sola vez, con el tamaño del primer cuadro (2048): sin soltarlo, la luz dibuja 4096 sobre una textura de 2048 y la esquina de Rivera Lara y Administración lee sombras que no son suyas.
  if(k.shadow.map){k.shadow.map.dispose();k.shadow.map=null}
  // La luz y los edificios no se mueven: las sombras se dibujan una vez y se repiten solo cuando cambia lo que las proyecta (interiores). Dibujar 4096 px en cada cuadro bloqueaba la página en equipos sin GPU y en celulares modestos.
  stage._renderer.shadowMap.autoUpdate=false;stage._renderer.shadowMap.needsUpdate=true;
  stage._ground.position.y=-1.2;}

// ---------- extras (no exportados) ----------
const fx=new THREE.Group();scene.add(fx);
const pinM=new THREE.MeshStandardMaterial({color:'#092d48',roughness:.4});
const pin=new THREE.Group();{const c=new THREE.Mesh(new THREE.ConeGeometry(2,6,24),pinM);c.rotation.x=Math.PI;c.position.y=4.2;const s=new THREE.Mesh(new THREE.SphereGeometry(2.8,24,16),pinM);s.position.y=8.6;pin.add(c,s)}fx.add(pin);
const placePin=()=>pin.position.set(S.origin[0],0,S.origin[1]);placePin();
const ring=new THREE.Mesh(new THREE.RingGeometry(1,1.1,64),new THREE.MeshBasicMaterial({color:'#092d48',transparent:true,opacity:.8,side:THREE.DoubleSide}));ring.rotation.x=-Math.PI/2;ring.position.y=.3;ring.visible=false;fx.add(ring);
const dotM=new THREE.MeshBasicMaterial({color:'#092d48'});const dots=[];for(let i=0;i<140;i++){const d=new THREE.Mesh(new THREE.SphereGeometry(.9,10,8),dotM);d.visible=false;fx.add(d);dots.push(d)}
const goalM=new THREE.Mesh(new THREE.TorusGeometry(2.4,.4,8,32),new THREE.MeshBasicMaterial({color:'#092d48'}));goalM.rotation.x=Math.PI/2;goalM.visible=false;fx.add(goalM);

// interiores
const INT={};
function interior(bid){if(INT[bid])return INT[bid];const st=BLD[bid];const out={floors:{},rooms:[]};
  const ghost=new THREE.Mesh(st.shell.geometry,new THREE.MeshBasicMaterial({color:'#092d48',transparent:true,opacity:.07,depthWrite:false}));ghost.rotation.x=-Math.PI/2;ghost.visible=false;fx.add(ghost);out.ghost=ghost;
  const edges=new THREE.LineSegments(new THREE.EdgesGeometry(st.shell.geometry,30),new THREE.LineBasicMaterial({color:'#092d48',transparent:true,opacity:.35}));edges.rotation.x=-Math.PI/2;edges.visible=false;fx.add(edges);out.edges=edges;
  for(const fl of [1,2]){const sp=spacesOf(bid,fl);if(!sp.length)continue;const g=new THREE.Group();g.visible=false;fx.add(g);const y=(fl-1)*FH;
    const slab=new THREE.Mesh(new THREE.ExtrudeGeometry(shapeOf(st.b.poly),{depth:.5,bevelEnabled:false}),new THREE.MeshStandardMaterial({color:'#F3EEE7',roughness:.95}));slab.rotation.x=-Math.PI/2;slab.position.y=y;slab.receiveShadow=true;g.add(slab);
    const wallM=new THREE.MeshStandardMaterial({color:'#FFFFFF',roughness:.9});
    for(const s of sp){const top=new THREE.MeshStandardMaterial({color:KIND_COLORS[s.kind]||'#E5DED3',roughness:.85});const hh=s.kind===4?.12:s.kind===15?.2:1.4;
      const geo=new THREE.ExtrudeGeometry(shapeOf(offsetPoly(s.poly,.22)),{depth:hh,bevelEnabled:false});const me=new THREE.Mesh(geo,[top,wallM]);me.rotation.x=-Math.PI/2;me.position.y=y+.5;me.castShadow=me.receiveShadow=true;
      me.userData.sid=s.id;me.userData.base=new THREE.Color(KIND_COLORS[s.kind]||'#E5DED3');me.userData.top=top;g.add(me);out.rooms.push(me);s.mesh=me;}
    out.floors[fl]=g;}
  INT[bid]=out;return out;}
let openSt=null;
const reshadow=()=>{stage._renderer.shadowMap.needsUpdate=true};
function openInterior(bid,fl){reshadow();if(openSt&&openSt!==bid)closeInterior(true);S.open=bid;const st=BLD[bid];const it=interior(bid);S.floor=fl&&it.floors[fl]?fl:(it.floors[S.floor]&&openSt===bid?S.floor:1);openSt=bid;
  if(!Object.keys(it.floors).length){S.open=null;openSt=null;return}
  Object.entries(it.floors).forEach(([f,g])=>g.visible=+f===S.floor);it.ghost.visible=it.edges.visible=true;
  st.g.visible=false;buildRoomLabels();renderFloorUI();}
function closeInterior(silent){if(!openSt)return;reshadow();const it=INT[openSt];Object.values(it.floors).forEach(g=>g.visible=false);it.ghost.visible=it.edges.visible=false;BLD[openSt].g.visible=true;openSt=null;S.open=null;S.selRoom=null;buildRoomLabels();if(!silent)renderFloorUI();}
function renderFloorUI(){const el=$('#floors');if(!S.open){el.classList.remove('ut-open');$('#kindlg').classList.remove('ut-open');return}
  const it=INT[S.open];$('#fl-name').textContent=B[S.open].name;
  $('#fl-seg').innerHTML=Object.keys(it.floors).map(f=>`<button data-f="${f}" class="${+f===S.floor?'ut-on':''}">P${f}</button>`).join('');el.classList.add('ut-open');
  const kinds=[...new Set(spacesOf(S.open,S.floor).map(s=>s.kind))].filter(k=>k!==4).sort((a,b)=>a-b);
  $('#kindlg').innerHTML=kinds.map(k=>`<span style="--c:${KIND_COLORS[k]};--on:${onC(KIND_COLORS[k])}"><i></i>${KINDS[k]}</span>`).join('');$('#kindlg').classList.add('ut-open');}
$('#fl-seg').onclick=e=>{const b=e.target.closest('[data-f]');if(!b)return;setFloor(+b.dataset.f)};
$('#fl-x').onclick=()=>{closeInterior();};
function setFloor(f){S.floor=f;openInterior(S.open,f);if(S.stack.length&&S.stack[S.stack.length-1].startsWith('b:'))renderDetail(S.stack[S.stack.length-1],true)}

// ---------- ruta ----------
let FIELD=null;
function fieldFrom(){const key=S.origin.join(',');if(FIELD&&FIELD.key===key)return FIELD;const [si,sj]=freeNear(S.origin);const r=gsearch(sj*GW+si,null);FIELD={key,g:r.g,prev:r.prev};return FIELD}
const IG={};
function indoorGrid(bid,fl){const key=bid+'|'+fl;if(IG[key])return IG[key];const b=B[bid];const C=.5;const xs=b.poly.map(p=>p[0]),zs=b.poly.map(p=>p[1]);const x0=Math.min(...xs)-1,z0=Math.min(...zs)-1;const gw=Math.ceil((Math.max(...xs)-x0+1)/C),gh=Math.ceil((Math.max(...zs)-z0+1)/C);
  const w=new Uint8Array(gw*gh);const rooms=spacesOf(bid,fl).filter(s=>s.kind!==4).map(s=>s.poly);
  for(let j=0;j<gh;j++)for(let i=0;i<gw;i++){const x=x0+(i+.5)*C,z=z0+(j+.5)*C;if(inPoly(b.poly,x,z)&&!rooms.some(r=>inPoly(r,x,z)))w[j*gw+i]=1}
  return IG[key]={C,x0,z0,gw,gh,w}}
const plen=pts=>pts.reduce((a,p,i)=>i?a+Math.hypot(p[0]-pts[i-1][0],p[1]-pts[i-1][1]):0,0);
function indoorPath(bid,fl,from,to,carvePolys){const {C,x0,z0,gw,gh,w}=indoorGrid(bid,fl);const ci=([x,z])=>[Math.max(0,Math.min(gw-1,Math.floor((x-x0)/C))),Math.max(0,Math.min(gh-1,Math.floor((z-z0)/C)))];
  const carve=new Set();carvePolys.forEach(p=>{const xs=p.map(q=>q[0]),zs=p.map(q=>q[1]);const [i0,j0]=ci([Math.min(...xs),Math.min(...zs)]),[i1,j1]=ci([Math.max(...xs),Math.max(...zs)]);for(let j=j0;j<=j1;j++)for(let i=i0;i<=i1;i++)if(inPoly(p,x0+(i+.5)*C,z0+(j+.5)*C))carve.add(j*gw+i)});
  const disc=(p,r)=>{const [a,b]=ci(p);const n=Math.ceil(r/C);for(let dj=-n;dj<=n;dj++)for(let di=-n;di<=n;di++){const i=a+di,j=b+dj;if(i>=0&&j>=0&&i<gw&&j<gh)carve.add(j*gw+i)}};disc(from,1.2);disc(to,.8);
  const ok=k=>w[k]||carve.has(k);const [si,sj]=ci(from),[ti,tj]=ci(to);const s=sj*gw+si,t=tj*gw+ti;const N=gw*gh;const g=new Float32Array(N).fill(Infinity),prev=new Int32Array(N).fill(-1),cl=new Uint8Array(N);const H=heap();g[s]=0;H.push(s,0);let found=false;
  while(H.h.length){const [,k]=H.pop();if(cl[k])continue;cl[k]=1;if(k===t){found=true;break}const i=k%gw,j=(k/gw)|0;for(let dj=-1;dj<=1;dj++)for(let di=-1;di<=1;di++){if(!di&&!dj)continue;const ni=i+di,nj=j+dj;if(ni<0||nj<0||ni>=gw||nj>=gh)continue;const nk=nj*gw+ni;if(!ok(nk))continue;if(di&&dj&&(!ok(j*gw+ni)||!ok(nj*gw+i)))continue;const ng=g[k]+(di&&dj?1.4142:1);if(ng<g[nk]){g[nk]=ng;prev[nk]=k;H.push(nk,ng+Math.hypot(ni-ti,nj-tj))}}}
  if(!found){const pts=[from,to];return{pts,len:plen(pts)*1.3,fallback:true}}
  const p=[];let c=t;while(c>=0){p.push(c);c=prev[c]}p.reverse();
  const L=(a,b)=>{let xa=a%gw,ya=(a/gw)|0;const xb=b%gw,yb=(b/gw)|0;const dx=Math.abs(xb-xa),dy=Math.abs(yb-ya),sx=xa<xb?1:-1,sy=ya<yb?1:-1;let e=dx-dy;for(;;){if(!ok(ya*gw+xa))return false;if(xa===xb&&ya===yb)return true;const e2=2*e;if(e2>-dy){e-=dy;xa+=sx}if(e2<dx){e+=dx;ya+=sy}}};
  const sp=[p[0]];let a=0;for(let k=2;k<p.length;k++)if(!L(p[a],p[k])){sp.push(p[k-1]);a=k-1}sp.push(p[p.length-1]);const pts=[from,...sp.slice(1,-1).map(k=>[x0+(k%gw+.5)*C,z0+(((k/gw)|0)+.5)*C]),to];
  return{pts,len:plen(pts)}}
let routeCurve=null;S.walk=null;S.steps=null;S.access=false;S.door=null;
const doorM=[];{const g1=new THREE.BoxGeometry(2.4,.2,1.1);DOORS.forEach(d=>{const m=new THREE.Mesh(g1,new THREE.MeshBasicMaterial({color:d.declared?'#092d48':'#8A96A3'}));m.position.set(d.p[0]+d.out[0]*.6,.22,d.p[1]+d.out[1]*.6);m.rotation.y=Math.atan2(d.out[0],d.out[1]);m.userData.door=d;fx.add(m);doorM.push(m)})}
function computeRoute(bid,sid){S.door=null;S.steps=null;if(!bid||!S.route){routeCurve=null;goalM.visible=false;dots.forEach(d=>d.visible=false);S.walk=null;return}
  const F=fieldFrom();const doors=DOORS_OF[bid]||[];const s=sid?SP[sid]:null;let best=null;
  for(const d of doors){const out=F.g[d.cell];if(!isFinite(out))continue;const plan={d,out:out*CELL,len:out*CELL,in:[]};
    if(s){if(s.floor===1){const r=indoorPath(bid,1,d.inP,s.c,[s.poly]);plan.in=[{fl:1,pts:r.pts}];plan.len+=r.len}
      else{const conK=S.access?10:2;let c1=spacesOf(bid,1).filter(x=>x.kind===conK),c2=spacesOf(bid,2).filter(x=>x.kind===conK);if(!c1.length||!c2.length){c1=spacesOf(bid,1).filter(x=>x.kind===2||x.kind===10);c2=spacesOf(bid,2).filter(x=>x.kind===2||x.kind===10)}
        let bc=null;for(const a of c1){const a2=c2.reduce((m,x)=>!m||Math.hypot(x.c[0]-a.c[0],x.c[1]-a.c[1])<Math.hypot(m.c[0]-a.c[0],m.c[1]-a.c[1])?x:m,null);if(!a2)continue;const r1=indoorPath(bid,1,d.inP,a.c,[a.poly]);const r2=indoorPath(bid,2,a2.c,s.c,[a2.poly,s.poly]);const Lt=r1.len+r2.len+FH;if(!bc||Lt<bc.L)bc={L:Lt,r1,r2,a,a2}}
        if(bc){plan.in=[{fl:1,pts:bc.r1.pts},{fl:2,pts:bc.r2.pts}];plan.len+=bc.L;plan.con=bc.a}else{plan.in=[{fl:2,pts:[d.inP,s.c]}];plan.len+=20}}}
    if(!best||plan.len<best.len)best=plan}
  if(!best){routeCurve=null;return}
  const path=[];let c=best.d.cell;while(c>=0){path.push(c);c=F.prev[c]}path.reverse();const simp=simplify(path);
  const Y1=.95,Y2=FH+.95;const pts=simp.map(k=>new THREE.Vector3(cx2(k%GW),.6,cz2((k/GW)|0)));pts.push(new THREE.Vector3(best.d.p[0],.6,best.d.p[1]));
  best.in.forEach((seg,si)=>{const y=seg.fl===1?Y1:Y2;if(si===1){const l=pts[pts.length-1];pts.push(new THREE.Vector3(l.x,Y2,l.z))}seg.pts.forEach(p=>pts.push(new THREE.Vector3(p[0],y,p[1])))});
  const cp=new THREE.CurvePath();for(let i=1;i<pts.length;i++)if(pts[i].distanceTo(pts[i-1])>.01)cp.add(new THREE.LineCurve3(pts[i-1],pts[i]));
  routeCurve=cp;S.walkM=Math.round(best.len);S.walk=Math.max(1,Math.round(best.len/75));S.door=best.d;goalM.visible=true;const last=pts[pts.length-1];goalM.position.copy(last).setY(last.y+.4);
  const bn=B[bid];const st=['Camina '+Math.round(best.out)+' m por los andadores hasta el '+(best.d.declared?'acceso':'acceso estimado')+' '+dirName(best.d.out)+' de '+bn.code+(best.d.declared?' (declarado en el repo)':'')+'.'];
  if(s){if(s.floor===2&&best.in.length===2){const conn=best.con;st.push('Entra y avanza '+Math.round(plen(best.in[0].pts))+' m hasta '+(conn?conn.name:'la escalera')+'.');st.push((conn&&conn.kind===10?'Toma el elevador':'Sube por la escalera')+' a la planta 2.');st.push('Sigue '+Math.round(plen(best.in[1].pts))+' m por el pasillo hasta '+s.name+'.')}
    else st.push('Entra y sigue '+Math.round(plen(best.in[0]?best.in[0].pts:[]))+' m por el pasillo hasta '+s.name+'.')}
  S.steps=st}


// ---------- etiquetas ----------
const labelsEl=$('#labels');const LBL={};
for(const b of D.BUILDINGS){const el=document.createElement('div');el.className='ut-lbl';el.innerHTML=`<span class="ut-dot">${esc(b.code)}</span><span class="ut-occ"></span><span class="ut-nm">${esc(b.name)}</span>`;labelsEl.appendChild(el);LBL[b.id]=el}
const pinEl=document.createElement('div');pinEl.id='pin';pinEl.textContent='Estás aquí';labelsEl.appendChild(pinEl);
let RL=[];
function buildRoomLabels(){RL.forEach(r=>r.el.remove());RL=[];if(!S.open)return;
  for(const s of spacesOf(S.open,S.floor)){if(s.kind===4||s.kind===14)continue;const g=KIND_GLYPH[s.kind];const el=document.createElement('div');el.className='ut-rl'+(g?' ut-g':'');el.textContent=g||(s.kind===3?s.raw:s.name);if(s.id===S.selRoom)el.classList.add('ut-sel');labelsEl.appendChild(el);
    let a=0;for(let i=0,j=s.poly.length-1;i<s.poly.length;j=i++)a+=s.poly[j][0]*s.poly[i][1]-s.poly[i][0]*s.poly[j][1];RL.push({el,s,v:new THREE.Vector3(s.c[0],(s.floor-1)*FH+2.2,s.c[1]),area:Math.abs(a/2)})}}

// ---------- resaltado ----------
const HL={focus:new Map(),sel:null,hover:null,hoverSet:null,hoverRoom:null};
const OCC_LO=new THREE.Color('#EFE6D8'),OCC_MID=new THREE.Color('#D9A08A'),OCC_HI=new THREE.Color('#6E2234');
const occOf=bid=>{const rs=spacesOf(bid).filter(s=>s.sched);if(!rs.length)return null;return rs.filter(r=>nowIn(r.id)).length/rs.length};
function targetFor(id){const st=BLD[id];if(PK.on){const hv=HL.hover===id;return{col:PK.bAll.has(id)?(PK.bEl.has(id)?PK.acc:PK.grey):PALE,em:hv&&PK.bAll.has(id)?.2:PK.bEl.has(id)?.1:0}}const any=HL.focus.size>0||HL.sel||S.occ;let col=st.base,em=0;
  if(S.occ){const o=occOf(id);col=o==null?PALE:o<.5?OCC_LO.clone().lerp(OCC_MID,o*2):OCC_MID.clone().lerp(OCC_HI,(o-.5)*2)}
  else if(any){const f=HL.focus.get(id);if(f){col=new THREE.Color(f);em=.12}else if(HL.sel!==id)col=PALE}
  if(HL.sel===id){em=.22;if(!HL.focus.get(id)&&!S.occ)col=st.base}
  if(HL.hover===id||HL.hoverSet?.has(id)){em+=.18;if(col===PALE)col=st.base}return{col,em}}

// ---------- elegir (modo pick, tarea 14) ----------
// Solo se enciende con window.UbicaTec.enablePick({eligible:[map_key], rejected:{map_key:motivo}}): los salones elegibles se resaltan
// con el acento, los demás se apagan (tocar o pasar el cursor sobre uno rechazado dice por qué) y tocar uno elegible emite
// `room-picked` en window con {key} (la map_key). Sin enablePick el mapa se comporta como siempre.
const PK={on:false,el:new Set(),rej:new Map(),bEl:new Set(),bAll:new Set(),acc:new THREE.Color('#1f6f4f'),grey:new THREE.Color('#B9B8B8')};
const SID2KEY={},KEY2SID={};for(const s of D.SPACES){const k=s.building+'|'+s.raw;if(!(k in KEY2SID)){KEY2SID[k]=s.id;SID2KEY[s.id]=k}}
const pickLegend=document.createElement('div');pickLegend.id='pklg';pickLegend.innerHTML='<span><i class="ut-pk-on"></i>Disponible</span><span><i class="ut-pk-off"></i>No disponible</span><small>Toca un edificio para ver sus salones</small>';rootEl.appendChild(pickLegend);
function enablePick(o){const el=o?.eligible||[],rj=o?.rejected||{};
  PK.el=new Set(el.map(k=>KEY2SID[k]).filter(Boolean));PK.rej=new Map(Object.entries(rj).filter(([k])=>KEY2SID[k]).map(([k,r])=>[KEY2SID[k],String(r)]));
  PK.bEl=new Set([...PK.el].map(i=>SP[i].building));PK.bAll=new Set([...PK.bEl,...[...PK.rej.keys()].map(i=>SP[i].building)]);
  {const cs=getComputedStyle(rootEl),tok=n=>{try{return new THREE.Color(cs.getPropertyValue(n).trim())}catch(e){return null}};
    const ok=cs.getPropertyValue('--color-success').trim();if(ok)try{PK.acc.set(ok)}catch(e){}
    const ne=cs.getPropertyValue('--color-neutral').trim(),bs=cs.getPropertyValue('--color-base-100').trim();
    if(ne)try{PK.grey.set(ne);if(bs)PK.grey.lerp(new THREE.Color(bs),.55)}catch(e){}}
  if(!PK.on){PK.on=true;rootEl.classList.add('ut-pickmode');S.stack=[];HL.focus=new Map();HL.sel=null;S.roomFocus=null;if(S.open)closeInterior()}
  if(S.selRoom&&!PK.el.has(S.selRoom))S.selRoom=null}
function disablePick(){if(!PK.on)return;PK.on=false;HL.hover=HL.hoverRoom=HL.hoverSet=null;PK.el=new Set();PK.rej=new Map();PK.bEl=new Set();PK.bAll=new Set();rootEl.classList.remove('ut-pickmode');S.selRoom=null;tip.style.opacity=0}
function pickTap({bid,sid}){
  if(sid){const s=SP[sid];if(PK.el.has(sid)){S.selRoom=sid;{const n=performance.now();if(PK.last&&PK.last.sid===sid&&n-PK.last.t<300)return;PK.last={sid,t:n}}window.dispatchEvent(new CustomEvent('room-picked',{detail:{key:SID2KEY[sid]}}))}else toast(PK.rej.has(sid)?`${s.name}: ${PK.rej.get(sid)}`:`${s.name} no está entre los salones disponibles`);return}
  if(bid){if(!PK.bAll.has(bid)){toast(`${B[bid].name}: sin salones para elegir`);return}
    const fl=[...PK.el,...PK.rej.keys()].map(i=>SP[i]).filter(s=>s.building===bid).map(s=>s.floor).sort()[0]||1;openInterior(bid,fl)}}

// ---------- cámara ----------
let tw=null;
function flyRaw(tp,tt,dur=1100){tw={t0:performance.now(),dur,fp:camera.position.clone(),ft:controls.target.clone(),tp,tt}}
function fly(tgt,dist){const top=S.view==='2d';const dir=top?new THREE.Vector3(0,1,.0001):camera.position.clone().sub(controls.target).normalize();if(!top&&dir.y<.6){dir.y=.6;dir.normalize()}
  const t=tgt.clone();if(MOB())t.z+=dist*.28;else t.x-=dist*.18;flyRaw(t.clone().add(dir.multiplyScalar(dist)),t)}
function flyTo(bids,close){if(!bids.length)return;const bb=new THREE.Box3();bids.forEach(id=>bb.expandByObject(BLD[id].shell));const c=bb.getCenter(new THREE.Vector3());const s=bb.getSize(new THREE.Vector3());
  const dist=Math.max(close?70:110,Math.max(s.x,s.z)*(close?1.5:1.6)+40)*(MOB()?1.5:1);fly(c.setY(0),dist)}
$('#vreset').onclick=()=>{const h=home();flyRaw(S.view==='2d'?h.tgt.clone().add(new THREE.Vector3(0,820,.1)):h.pos.clone(),h.tgt.clone())};
function setView(v){S.view=v;$('#v3').classList.toggle('ut-on',v==='3d');$('#v2').classList.toggle('ut-on',v==='2d');$('#v3').setAttribute('aria-pressed',v==='3d');$('#v2').setAttribute('aria-pressed',v==='2d');controls.enableRotate=v==='3d';controls.maxPolarAngle=v==='2d'?.001:Math.PI*.46;
  const t=controls.target.clone();const d=camera.position.distanceTo(t);flyRaw(v==='2d'?t.clone().add(new THREE.Vector3(0,Math.max(d,200),.1)):t.clone().add(new THREE.Vector3(.3,.75,.85).normalize().multiplyScalar(d)),t,1000)}
$('#v3').onclick=()=>setView('3d');$('#v2').onclick=()=>setView('2d');

// ---------- bucle ----------
const ease=t=>t<.5?4*t*t*t:1-Math.pow(-2*t+2,3)/2;const v3=new THREE.Vector3();const SELC=new THREE.Color('#6E2234');
function loop(now){if(!alive)return;rafId=requestAnimationFrame(loop);
  {const dd=camera.position.distanceTo(controls.target);const nr=Math.max(1,Math.min(40,dd*.04)),fr=Math.max(2500,dd*12);if(Math.abs(camera.near-nr)>.05*nr||camera.far!==fr){camera.near=nr;camera.far=fr;camera.updateProjectionMatrix()}}
  if(tw){const k=Math.min(1,(now-tw.t0)/tw.dur),e=ease(k);camera.position.lerpVectors(tw.fp,tw.tp,e);controls.target.lerpVectors(tw.ft,tw.tt,e);if(k>=1)tw=null}
  const t=now/1000;
  for(const id in BLD){const st=BLD[id];const tg=targetFor(id);st.cur.col.lerp(tg.col,.12);st.cur.em+=(tg.em-st.cur.em)*.12;st.m.color.copy(st.cur.col);st.m.emissive.copy(st.cur.col).multiplyScalar(st.cur.em*(.75+.25*Math.sin(t*3)))}
  if(S.open){for(const me of INT[S.open].rooms){const s=SP[me.userData.sid];const top=me.userData.top;const sel=s.id===S.selRoom,hov=s.id===HL.hoverRoom;const f=S.roomFocus?.has(s.id);
    if(PK.on){const el=PK.el.has(s.id),isRoom=s.kind===3||s.kind===12;top.color.lerp(sel?SELC:el?PK.acc:isRoom?PK.grey:me.userData.base,.15);top.emissive.copy(top.color).multiplyScalar(sel?.25:el?(hov?.35:.12+.08*Math.sin(t*3)):0);me.position.y=(s.floor-1)*FH+.5+(sel?.6:el&&hov?.4:0);continue}
    top.color.lerp(sel?SELC:f?new THREE.Color(AC(s.owner)):me.userData.base,.15);top.emissive.copy(top.color).multiplyScalar(sel?.25+.15*Math.sin(t*4):hov?.25:0);me.position.y=(s.floor-1)*FH+.5+(sel?.6+.3*Math.sin(t*4):hov?.3:0)}}
  if(HL.sel&&!S.open){const st=BLD[HL.sel];ring.visible=true;ring.position.x=st.center.x;ring.position.z=st.center.z;const p=(t*14)%12;ring.scale.setScalar(st.r+4+p);ring.material.opacity=.85*(1-p/12)}else ring.visible=false;
  if(routeCurve){const L=routeCurve.getLength();const n=Math.min(dots.length,Math.max(8,Math.round(L/4)));dots.forEach((d,i)=>{if(i>=n){d.visible=false;return}const u=((i/n)+t*(10/L))%1;routeCurve.getPointAt(u,d.position);d.position.y+=Math.abs(Math.sin(u*L/3+t*5))*.35;d.visible=true});goalM.rotation.z=t*2;}
  pin.position.y=Math.abs(Math.sin(t*2.4))*1.6;
  for(const m of doorM){const on=S.door===m.userData.door;const vis=on||S.open===m.userData.door.b||HL.sel===m.userData.door.b;m.visible=vis;m.scale.setScalar(on?1.3+.25*Math.sin(t*5):1)}
  const w=rootEl.clientWidth,h=rootEl.clientHeight;const any=HL.focus.size>0||HL.sel;
  for(const id in BLD){const st=BLD[id],el=LBL[id];v3.copy(st.center);v3.y=st.H+4;v3.project(camera);if(v3.z>1||(S.open===id)){el.style.opacity=0;continue}
    el.style.transform=`translate(${(v3.x*.5+.5)*w-12}px,${(-v3.y*.5+.5)*h-12}px)`;el.style.opacity='';const f=HL.focus.get(id);el.classList.toggle('ut-on',!!f||HL.sel===id);if(f){el.style.setProperty('--c',f);el.style.setProperty('--on',onC(f))}else{el.style.removeProperty('--c');el.style.removeProperty('--on')}
    el.classList.toggle('ut-sel',HL.sel===id);el.classList.toggle('ut-dim',!!any&&!f&&HL.sel!==id&&HL.hover!==id||(!!S.open));el.classList.toggle('ut-show',HL.sel===id||HL.hover===id||!!HL.hoverSet?.has(id))}
  if(RL.length){const dist=camera.position.distanceTo(controls.target);const placed=[];const order=RL.slice().sort((a,b)=>(b.s.id===S.selRoom)-(a.s.id===S.selRoom)||b.area-a.area);for(const r of order){v3.copy(r.v).project(camera);const x=(v3.x*.5+.5)*w,y=(-v3.y*.5+.5)*h;let show=r.s.id===S.selRoom||r.area*(260/Math.max(60,dist))**2>14||dist<70;if(show){r.bw??=r.el.offsetWidth||40;r.bh??=r.el.offsetHeight||16;const bx=[x-r.bw/2-2,y-r.bh/2-2,x+r.bw/2+2,y+r.bh/2+2];if(r.s.id!==S.selRoom&&placed.some(q=>bx[0]<q[2]&&bx[2]>q[0]&&bx[1]<q[3]&&bx[3]>q[1]))show=false;else placed.push(bx)}r.el.style.opacity=show?1:0;r.el.style.transform=`translate(-50%,-50%) translate(${x}px,${y}px)`}}
  v3.copy(pin.position).setY(14).project(camera);pinEl.style.transform=`translate(${(v3.x*.5+.5)*w+8}px,${(-v3.y*.5+.5)*h-8}px)`;
  const az=Math.atan2(camera.position.x-controls.target.x,camera.position.z-controls.target.z);const nd=$('#compass .ut-nd'),nl=$('#compass .ut-nl');nd.style.transform=`rotate(${-az}rad)`;nl.style.transform=`translate(${Math.sin(-az)*11}px,${-Math.cos(-az)*11}px)`;}
rafId=requestAnimationFrame(loop);

// ---------- puntero ----------
const ray=new THREE.Raycaster(),mp=new THREE.Vector2();let down=null;const tip=$('#tip');const gplane=new THREE.Plane(new THREE.Vector3(0,1,0),0);
function setRay(e){const r=stage.getBoundingClientRect();mp.set((e.clientX-r.left)/r.width*2-1,-(e.clientY-r.top)/r.height*2+1);ray.setFromCamera(mp,camera)}
function hit(e){setRay(e);const objs=S.open?[...INT[S.open].floors[S.floor].children.filter(c=>c.userData.sid),...pickB]:pickB;const hh=ray.intersectObjects(objs,false).find(x=>x.object.visible&&(x.object.userData.sid||x.object.parent.visible));if(!hh)return{};return hh.object.userData.sid?{sid:hh.object.userData.sid}:{bid:hh.object.userData.bid}}
stage.addEventListener('pointermove',e=>{if(e.pointerType==='touch')return;const {bid,sid}=hit(e);HL.hover=bid||null;HL.hoverRoom=sid||null;stage.style.cursor=bid||sid||S.picking?'pointer':'';
  if(sid&&PK.on&&(PK.el.has(sid)||PK.rej.has(sid))){const s=SP[sid];tip.innerHTML=PK.el.has(sid)?`<b>${esc(s.name)}</b>Disponible · toca para elegirlo`:`<b>${esc(s.name)}</b>${esc(PK.rej.get(sid))}`}
  else if(sid){const s=SP[sid];const c=nowIn(sid);tip.innerHTML=`<b>${esc(s.name)}</b>${KINDS[s.kind]} · P${s.floor}${c?` · En clase: ${esc(sOf(c))}`:''}`}
  else if(bid){const b=B[bid];const rs=spacesOf(bid).filter(s=>s.sched);const live=rs.filter(r=>nowIn(r.id)).length;tip.innerHTML=`<b>${esc(b.code)} · ${esc(b.name)}</b>${esc(b.cat)} · ${b.floors} ${b.floors>1?'plantas':'planta'}${rs.length?` · ${live}/${rs.length} en clase`:''}`}
  tip.style.opacity=bid||sid?1:0;{const rr=rootEl.getBoundingClientRect();tip.style.left=e.clientX-rr.left+14+'px';tip.style.top=e.clientY-rr.top+14+'px'}});
stage.addEventListener('pointerleave',()=>{HL.hover=HL.hoverRoom=null;tip.style.opacity=0});
stage.addEventListener('pointerdown',e=>down=[e.clientX,e.clientY]);
stage.addEventListener('pointerup',e=>{if(!down||Math.hypot(e.clientX-down[0],e.clientY-down[1])>6)return;
  if(S.picking){setRay(e);const p=new THREE.Vector3();if(ray.ray.intersectPlane(gplane,p)){const [i,j]=freeNear([p.x,p.z]);S.origin=[cx2(i),cz2(j)];placePin();toast('Punto de partida actualizado')}S.picking=false;rootEl.classList.remove('ut-picking');rerouteCurrent();return}
  if(PK.on){pickTap(hit(e));return}
  const {bid,sid}=hit(e);if(sid){const s=SP[sid];if(EK['s:'+sid])open('s:'+sid);else toast(`${s.name} · ${KINDS[s.kind]} · P${s.floor}`)}else if(bid)open('b:'+bid)});

// ---------- entidades / búsqueda ----------
const ENT=[],EK={};const add=e=>{e.n=norm(e.title);e.text=norm(e.text+' '+e.title);ENT.push(e);EK[e.key]=e};
const SEARCH_K=new Set([3,6,8,9,5,10,11,12,13,15]);
const kindWords={3:'salon salones aula aulas',12:'laboratorio laboratorios lab labs',6:'oficina oficinas',8:'bano banos wc sanitario hombres',9:'bano banos wc sanitario mujeres',5:'bano banos wc',10:'elevador',11:'tienda comida cafe venta copias',13:'descanso estudiar sala',15:'cancha'};
for(const [k,a] of Object.entries(AREAS))add({key:'a:'+k,type:'area',title:a.name,sub:`${areaB[k].size} edificios · ${D.SUBJECTS.filter(s=>s.area===k).length} materias · ${D.PROFS.filter(p=>p.area===k).length} profesores`,badge:a.abbr,areas:[k],bids:[...areaB[k]],text:a.alias+' departamento area'});
for(const b of D.BUILDINGS)add({key:'b:'+b.id,type:'building',title:b.name,sub:`${b.code} · ${b.cat} · ${b.floors} ${b.floors>1?'plantas':'planta'}`,badge:b.code,areas:b.areas,bids:[b.id],text:`${b.code} ${b.id} edificio ${b.cat} ${b.syn||''} `+b.areas.map(AL).join(' ')});
for(const d of D.DEPTS)add({key:'d:'+d.id,type:'dept',title:'Departamento de '+d.name,sub:[B[d.b].name,d.phone].filter(Boolean).join(' · '),badge:B[d.b].code,areas:[d.area],bids:[d.b],text:`departamento dpto depto jefatura ${AL(d.area)}`});
for(const s of D.SPACES){if(!SEARCH_K.has(s.kind))continue;const b=B[s.building];add({key:'s:'+s.id,type:s.kind===3||s.kind===12?'room':s.kind===6?'office':'service',title:s.name,sub:`${b.name} · P${s.floor}${s.owner?' · '+AN(s.owner):''}`,badge:s.kind===3&&/^\d+$/.test(s.raw)?s.raw:b.code,areas:s.owner?[s.owner]:[],bids:[s.building],sid:s.id,text:`${s.raw} ${s.code||''} ${KINDS[s.kind]} ${kindWords[s.kind]||''} ${b.name} ${b.code} ${s.syn||''} ${s.owner?AL(s.owner):''} planta ${s.floor} piso ${s.floor}`})}
for(const s of D.SUBJECTS){const cl=bySubj[s.id]||[];add({key:'m:'+s.id,type:'subject',title:s.name,sub:`${s.id} · ${AN(s.area)} · ${new Set(cl.map(c=>c.group)).size} grupos`,badge:AB(s.area),areas:s.area?[s.area]:[],bids:[...new Set(cl.map(c=>bOfRoom(c.room)).filter(Boolean))],text:`${s.id} materia materias asignatura clase ${AL(s.area)}`})}
for(const g of D.GROUPS){const cl=byGroup[g.id]||[];const sn=SU[g.subject]?.name||g.subject;add({key:'g:'+g.id,type:'group',title:`${sn} · grupo ${g.code}`,sub:[AN(g.area),g.turno,g.career].filter(Boolean).join(' · ')+` · ${cl.length} ${cl.length===1?'horario':'horarios'}`,badge:g.code,areas:g.area?[g.area]:[],bids:[...new Set(cl.map(c=>bOfRoom(c.room)).filter(Boolean))],text:`${g.id} ${g.subject} ${g.code} grupo grupos ${AL(g.area)}`})}
for(const p of D.PROFS){const cl=byProf[p.id]||[];add({key:'p:'+p.id,type:'prof',title:pName(p),sub:[AN(p.area),p.loc,`${cl.length} ${cl.length===1?'clase':'clases'}`].filter(Boolean).join(' · '),badge:p.name.split(' ').map(s=>s[0]).slice(0,2).join(''),areas:p.area?[p.area]:[],bids:[...new Set([p.building,...cl.map(c=>bOfRoom(c.room))].filter(Boolean))],text:`profe profes profesor profesora profesores maestro maestra docente ${p.email||''} ${AL(p.area)}`})}
const TYPES=[['area','Carreras y áreas'],['building','Edificios'],['dept','Departamentos'],['room','Salones y laboratorios'],['office','Oficinas'],['service','Servicios, baños y descanso'],['subject','Materias'],['group','Grupos'],['prof','Profesores']];
const STOP=new Set(['de','del','la','las','el','los','en','y','a','para','con','que','donde','esta','estan']);
function search(q){const toks=norm(q).split(/\s+/).filter(t=>t&&!STOP.has(t));let list=ENT;if(S.areas.size)list=list.filter(e=>e.areas.some(a=>S.areas.has(a)));
  if(!toks.length)return S.areas.size?list.map(e=>({e,s:0})):[];const nq=norm(q).trim();const out=[];
  for(const e of list){if(!toks.every(t=>e.text.includes(t)))continue;let s=0;if(e.n.startsWith(nq))s+=60;else if(e.n.includes(nq))s+=30;toks.forEach(t=>{if(e.n.includes(t))s+=8});if(e.type==='area'||e.type==='dept')s+=12;if(e.type==='building')s+=6;out.push({e,s})}
  return out.sort((a,b)=>b.s-a.s)}
const hlText=(t,q)=>{const toks=norm(q).split(/\s+/).filter(x=>x.length>1&&!STOP.has(x));if(!toks.length)return esc(t);const n=norm(t);const mk=new Array(t.length).fill(false);toks.forEach(k=>{let i=n.indexOf(k);while(i>=0){for(let j=i;j<i+k.length;j++)mk[j]=true;i=n.indexOf(k,i+1)}});let o='',on=false;for(let i=0;i<t.length;i++){if(mk[i]!==on){o+=mk[i]?'<mark>':'</mark>';on=mk[i]}o+=esc(t[i])}return o+(on?'</mark>':'')};
const colorOf=e=>{if(e.sid&&!SP[e.sid].owner&&!SP[e.sid].sched)return KIND_COLORS[SP[e.sid].kind]&&e.type!=='office'?'#8C7B6B':'#B5362C';if(e.type==='building')return e.areas.length===1?AC(e.areas[0]):'#B5362C';return e.areas[0]?AC(e.areas[0]):'#6E2234'};

// ---------- UI ----------
const pb=$('#pb'),qEl=$('#q'),panel=$('#panel');
$('#chips').innerHTML=Object.entries(AREAS).map(([k,a])=>`<button class="ut-chip" data-a="${k}" style="--c:${a.color};--on:${onC(a.color)}"><i></i>${esc(a.short)}</button>`).join('');
$('#chips').onclick=e=>{const c=e.target.closest('.ut-chip');if(!c)return;const a=c.dataset.a;S.areas.has(a)?S.areas.delete(a):S.areas.add(a);c.classList.toggle('ut-on');S.stack=[];closeDrawer();syncHash();render();snap('half')};
qEl.oninput=()=>{S.q=qEl.value;$('#sbox').classList.toggle('ut-has',!!S.q);S.stack=[];S.expanded={};closeDrawer();syncHash();render()};
qEl.onfocus=()=>snap('full');
$('#clr').onclick=()=>{qEl.value='';qEl.oninput();qEl.focus()};
addEventListener('keydown',e=>{if(!rootEl.isConnected)return;const ed=e.target?.closest?.('input,textarea,select,[contenteditable]');if(e.key==='/'&&!ed&&document.activeElement!==qEl){e.preventDefault();qEl.focus()}if(e.key==='Escape'&&(!ed||ed===qEl)){if($('#drawer').classList.contains('ut-open'))closeDrawer();else if(S.stack.length)back();else if(S.open)closeInterior();else if(S.q){qEl.value='';qEl.oninput()}}},sig);
const itemHTML=(e,i,q,tag)=>`<div class="ut-it" role="button" tabindex="0" data-k="${e.key}" style="--c:${colorOf(e)};--on:${onC(colorOf(e))};animation-delay:${Math.min(i,12)*25}ms"><div class="ut-bd${e.type==='prof'||e.type==='group'?' ut-o':''}">${esc(e.badge)}</div><div class="ut-tx"><div class="ut-t">${hlText(e.title,q)}</div><div class="ut-s">${esc(e.sub)}</div></div>${tag||''}</div>`;
const liveTag=e=>e.sid&&nowIn(e.sid)?'<span class="ut-tag ut-live">En clase</span>':'';

const LS={get:k=>{try{const v=JSON.parse(localStorage.getItem(k));return Array.isArray(v)?v:[]}catch(e){return[]}},set:(k,v)=>{try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}};
let FAV=LS.get('ubicatec.fav'),REC=LS.get('ubicatec.recent');
const syncHash=()=>{const k=S.stack[S.stack.length-1];history.replaceState(history.state,'',k?'#'+encodeURIComponent(k):location.pathname+location.search)};
function open(k){if(!EK[k]||S.stack[S.stack.length-1]===k)return;S.stack.push(k);window.dispatchEvent(new CustomEvent('ubicatec:open',{detail:{key:k}}));HL.hoverSet=null;REC=[k,...REC.filter(x=>x!==k)].slice(0,8);LS.set('ubicatec.recent',REC);syncHash();render();snap('half')}
function back(){S.stack.pop();closeDrawer();syncHash();render()}
function toast(msg){let t=$('#toast');if(!t){t=document.createElement('div');t.id='toast';t.setAttribute('role','status');t.setAttribute('aria-live','polite');rootEl.appendChild(t)}t.textContent=msg;t.style.opacity=1;clearTimeout(t._h);t._h=setTimeout(()=>t.style.opacity=0,1800)}

function render(){panel.classList.toggle('ut-detail',S.stack.length>0);if(S.stack.length)return renderDetail(S.stack[S.stack.length-1]);
  HL.sel=null;S.selRoom=null;S.roomFocus=null;computeRoute(null);if(S.open)closeInterior();
  const res=search(S.q);
  if(!res.length&&!S.q&&!S.areas.size){HL.focus=new Map();return renderHome()}
  const fm=new Map();res.slice(0,400).forEach(({e})=>e.bids.forEach(b=>{if(!fm.has(b))fm.set(b,colorOf(e))}));if(S.areas.size&&!S.q)S.areas.forEach(a=>areaB[a].forEach(b=>fm.set(b,AC(a))));HL.focus=fm;
  S.roomFocus=new Set(res.filter(r=>r.e.sid).map(r=>r.e.sid));
  if(!res.length){pb.innerHTML=`<div class="ut-hint" style="padding:20px 8px">Sin resultados para “${esc(S.q)}”. Prueba con un número de salón, una materia o el nombre de un docente.</div>`;return}
  let h=`<div class="ut-hint" style="padding:8px 8px 0;font-size:12px">${res.length} resultados · ${fm.size} edificios resaltados</div>`;
  for(const [t,label] of TYPES){const items=res.filter(r=>r.e.type===t);if(!items.length)continue;const lim=S.expanded[t]?80:(t==='area'?3:5);
    h+=`<div class="ut-sec"><h2><span>${label}</span><span>${items.length}</span></h2>${items.slice(0,lim).map((r,i)=>itemHTML(r.e,i,S.q,liveTag(r.e))).join('')}${items.length>lim?`<button class="ut-more" data-x="${t}">Ver los ${items.length} →</button>`:''}</div>`}
  pb.innerHTML=h;pb.scrollTop=0}
function suggestions(){const d=D.DEPTS[0],r=D.SPACES.find(s=>s.sched&&s.kind===3),m=D.SUBJECTS.find(x=>(bySubj[x.id]||[]).length),l=D.SPACES.find(s=>s.sched&&s.kind===12);
  return[d&&'Departamento de '+d.name,r&&r.name,m&&m.name,l&&l.name].filter(Boolean)}
function renderHome(){const live=D.CLASSES.filter(liveAt);
  const mini=(arr,title)=>{const a=arr.filter(k=>EK[k]);return a.length?`<div class="ut-sec"><h2><span>${title}</span><span>${a.length}</span></h2>${a.map((k,i)=>itemHTML(EK[k],i,'')).join('')}</div>`:''};
  pb.innerHTML=mini(FAV,'Favoritos')+mini(REC.slice(0,5),'Recientes')+`<div class="ut-sec"><h2>Prueba a buscar</h2><div class="ut-sugg">${suggestions().map(s=>`<button data-q="${esc(s)}">${esc(s)}</button>`).join('')}</div></div>
  ${!D.CLASSES.length?`<div class="ut-empty" role="status"><b>Sin clases publicadas en ${esc(D.SRV.term?.name||'este periodo')}</b><span>El mapa muestra los edificios, las oficinas y los servicios. Para ver las clases de otro semestre, elige su periodo en tu credencial.</span></div>`:`<div class="ut-sec"><h2><span>En clase ahora · ${D.DAYS_LONG[S.day]} ${S.hour}:00</span><span>${live.length}</span></h2>${live.length?'':'<div class="ut-hint">Ninguna clase a esta hora.</div>'}${live.slice(0,6).map((c,i)=>{const s=SU[c.subject],bb=rBld(c);return `<div class="ut-it" role="button" tabindex="0" data-k="${c.room?'s:'+c.room:'m:'+c.subject}" style="--c:${AC(s?.area)};--on:${onC(AC(s?.area))};animation-delay:${i*25}ms"><div class="ut-bd">${esc(bb?.code||'·')}</div><div class="ut-tx"><div class="ut-t">${esc(sOf(c))}</div><div class="ut-s">${esc(rName(c))} · ${esc(gOf(c))} · ${esc(pOf(c))}</div></div><span class="ut-tag ut-live">${hrs(c)}</span></div>`}).join('')}</div>`}
  <div class="ut-sec"><h2><span>Edificios</span><span>${D.BUILDINGS.length}</span></h2>${D.BUILDINGS.slice().sort((a,b)=>a.name.localeCompare(b.name)).map((b,i)=>itemHTML(EK['b:'+b.id],i,'')).join('')}</div>`}
function kbAct(e){if((e.key==='Enter'||e.key===' ')&&e.target.matches('[role=button]')){e.preventDefault();e.target.click();requestAnimationFrame(()=>pb.querySelector('[data-act="back"]')?.focus({preventScroll:true}))}}
pb.addEventListener('keydown',kbAct);
pb.addEventListener('click',e=>{const s=e.target.closest('[data-q]');if(s){qEl.value=s.dataset.q;qEl.oninput();return}const m=e.target.closest('[data-x]');if(m){S.expanded[m.dataset.x]=1;render();return}
  const a=e.target.closest('[data-act]');if(a)return act(a.dataset.act,a);const it=e.target.closest('[data-k]');if(it)open(it.dataset.k)});
pb.addEventListener('mouseover',e=>{const it=e.target.closest('[data-k]');const en=it&&EK[it.dataset.k];HL.hoverSet=en?new Set(en.bids):null;HL.hoverRoom=en?.sid||null});
pb.addEventListener('mouseleave',()=>{HL.hoverSet=null;HL.hoverRoom=null});

const fact=(b,s)=>`<div class="ut-fact"><b>${b}</b><span>${s}</span></div>`;
const backBtn=()=>{const k=S.stack[S.stack.length-1];const f=FAV.includes(k);return `<div style="display:flex;align-items:center;gap:6px"><button class="ut-back" data-act="back" style="flex:1">← ${S.stack.length>1?'Atrás':(S.q||S.areas.size?'Resultados':'Inicio')}</button><button class="ut-btn ut-sec2" data-act="fav" style="padding:7px 10px">${f?'★ Guardado':'☆ Guardar'}</button><button class="ut-btn ut-sec2" data-act="share" style="padding:7px 10px">Compartir</button></div>`};
const head=(badge,c,kind,title,sub)=>`<div class="ut-dh" style="--c:${c};--on:${onC(c)}"><div class="ut-bd">${esc(badge)}</div><div><div class="ut-k">${esc(kind)}</div><h2>${esc(title)}</h2>${sub?`<div style="font-size:13px;color:var(--ink2)">${sub}</div>`:''}</div></div>`;
const photo=src=>src?`<img class="ut-photo" src="${src}" alt="" loading="lazy" onerror="this.remove()">`:'';
const contact=o=>{const r=[];if(o.phone)r.push(`<span><b>Teléfono</b>${esc(o.phone)}</span>`);if(o.email)r.push(`<a href="mailto:${esc(o.email)}"><b>Correo</b>${esc(o.email)}</a>`);if(o.location)r.push(`<span><b>Ubicación</b>${esc(o.location)}</span>`);if(o.web)r.push(`<a href="${esc(o.web)}" target="_blank" rel="noopener"><b>Sitio</b>${esc(o.web.replace(/^https?:\/\//,''))}</a>`);if(o.fb)r.push(`<a href="${o.fb}" target="_blank"><b>Facebook</b>Página del departamento</a>`);return r.length?`<div class="ut-contact">${r.join('')}</div>`:''};
const routeBtn=()=>`<button class="ut-btn ut-sec2" data-act="route">${S.route?'Ocultar ruta':'Cómo llegar'}</button>${S.route?'<button class="ut-btn ut-sec2" data-act="origin">Cambiar partida</button>':''}`;
const clsItem=(c,mode,i)=>{const s=SU[c.subject],bb=rBld(c);const days=c.days.map(d=>D.DAYS[d]).join(' ');const live=liveAt(c);const when=`${days} ${hrs(c)}`;
  const t=mode==='subject'?`${gOf(c)} · ${pOf(c)}`:sOf(c);const sub=mode==='room'?`${gOf(c)} · ${pOf(c)} · ${when}`:mode==='subject'?[rName(c),bb?.code,when].filter(Boolean).join(' · '):[rName(c),bb?.code,mode==='prof'?gOf(c):pOf(c),when].filter(Boolean).join(' · ');
  const k=mode==='room'?(c.prof&&PR[c.prof]?'p:'+c.prof:''):(c.room?'s:'+c.room:'m:'+c.subject);
  return `<div class="ut-it" role="button" tabindex="0"${k?` data-k="${k}"`:''} style="--c:${AC(s?.area)};--on:${onC(AC(s?.area))};animation-delay:${Math.min(i,12)*25}ms"><div class="ut-bd">${esc(bb?.code||'·')}</div><div class="ut-tx"><div class="ut-t">${esc(t)}</div><div class="ut-s">${esc(sub)}</div></div>${live?'<span class="ut-tag ut-live">Ahora</span>':''}</div>`};

function renderDetail(k,keepCam){
  const i=k.indexOf(':'),t=k.slice(0,i),id=k.slice(i+1);const e=EK[k];
  let h=backBtn(),bids=e?e.bids:[],selB=null,selS=null,fl=null,close=false;
  if(t==='b'){const b=B[id];selB=id;const it=interior(id);const hasInt=Object.keys(it.floors).length;fl=hasInt?(S.open===id?S.floor:1):null;
    const all=spacesOf(id);const rooms=all.filter(s=>s.sched);const live=rooms.filter(r=>nowIn(r.id));const dept=D.DEPTS.find(d=>d.b===id);
    h+=head(b.code,b.areas.length===1?AC(b.areas[0]):'#B5362C',b.cat,b.name,b.areas.map(a=>`<span style="color:${AC(a)};font-weight:700">● ${esc(AN(a))}</span>`).join(' &nbsp;'));
    h+=photo(b.img);if(b.desc)h+=`<div class="ut-hint" style="padding:0 0 12px">${esc(b.desc)}</div>`;h+=contact(dept||b);
    h+=`<div class="ut-facts">${fact('Plantas',b.floors)}${fact('Espacios',all.filter(s=>s.kind!==4).length||'—')}${fact('Con horario',rooms.length||'—')}${fact('En clase ahora',rooms.length?`${live.length} de ${rooms.length}`:'—')}</div>`;
    h+=`<div class="ut-acts">${routeBtn()}${rooms.length?`<button class="ut-btn ut-sec2" data-act="bsched">Horario del edificio</button>`:''}</div>`;
    if(dept)h+=`<div class="ut-sec"><h2>Departamento</h2>${itemHTML(EK['d:'+dept.id],0,'')}</div>`;
    if(hasInt){h+=`<div class="ut-sec"><h2><span>Espacios por planta</span><span>${all.length}</span></h2><div class="ut-ftabs">${Object.keys(it.floors).map(f=>`<button data-act="floor" data-f="${f}" class="${+f===fl?'ut-on':''}">Planta ${f}</button>`).join('')}</div>`;
      const fs=spacesOf(id,fl).filter(s=>s.kind!==4);const kc={};fs.forEach(s=>kc[s.kind]=(kc[s.kind]||0)+1);
      h+=`<div class="ut-kgrid">${Object.entries(kc).map(([k2,n])=>`<span style="--c:${KIND_COLORS[k2]};--on:${onC(KIND_COLORS[k2])}"><i></i>${KINDS[k2]} · ${n}</span>`).join('')}</div>`;
      const listed=fs.filter(s=>EK['s:'+s.id]&&![8,9,5,10].includes(s.kind)).sort((a,b)=>a.kind-b.kind||a.name.localeCompare(b.name,'es',{numeric:true}));
      h+=listed.slice(0,S.expanded.bl?200:12).map((s,i)=>itemHTML(EK['s:'+s.id],i,'',liveTag(EK['s:'+s.id]))).join('')+(listed.length>12&&!S.expanded.bl?`<button class="ut-more" data-act="x:bl">Ver los ${listed.length} →</button>`:'')+'</div>'}}
  else if(t==='s'){const s=SP[id];const b=B[s.building];selB=s.building;selS=id;fl=s.floor;close=true;const c=s.sched&&nowIn(id);const cl=byRoom[id]||[];
    h+=head(s.kind===3&&/^\d+$/.test(s.raw)?s.raw:b.code,s.owner?AC(s.owner):s.sched?'#092d48':(s.kind===6?'#B5362C':'#8C7B6B'),`${KINDS[s.kind]} · Planta ${s.floor}`,s.name,esc(b.name));
    h+=photo(s.img);if(s.desc)h+=`<div class="ut-hint" style="padding:0 0 12px">${esc(s.desc)}</div>`;
    if(s.sched){h+=c?`<div class="ut-now ut-busy"><span class="ut-pulse"></span><div><b>En clase ahora:</b> ${esc(sOf(c))} · ${esc(gOf(c))} · ${esc(pOf(c))}</div></div>`:`<div class="ut-now ut-free"><span class="ut-pulse"></span><div><b>Libre</b> · ${D.DAYS_LONG[S.day]} ${S.hour}:00</div></div>`;
      h+=`<div class="ut-facts">${fact('Planta',s.floor)}${fact('Capacidad',s.cap?s.cap+' personas':'—')}${fact('Tipo',s.type==='laboratorio'?'Laboratorio':s.type==='aula'?'Aula':esc(KINDS[s.kind]))}${fact('Uso semanal',`${cl.reduce((a,c)=>a+c.days.length*dur(c),0)} h de ${D.DAYS.length*D.HOURS.length}`)}</div>`;
      h+=`<div class="ut-acts"><button class="ut-btn" data-act="sched">Horario semanal</button>${routeBtn()}</div>`;
      const profs=[...new Set(cl.map(c=>c.prof))].filter(p=>p&&EK['p:'+p]);if(profs.length)h+=`<div class="ut-sec"><h2><span>Docentes que imparten aquí</span><span>${profs.length}</span></h2>${profs.map((p,i)=>itemHTML(EK['p:'+p],i,'')).join('')}</div>`;
      openDrawer('room',id)}
    else{h+=`<div class="ut-facts">${fact('Edificio',esc(b.code))}${fact('Planta',s.floor)}</div><div class="ut-acts">${routeBtn()}<button class="ut-btn ut-sec2" data-k2="b:${b.id}">Ver edificio</button></div>`;
      const near=spacesOf(s.building,s.floor).filter(x=>[8,9,5].includes(x.kind));if(near.length&&![8,9,5].includes(s.kind))h+=`<div class="ut-sec"><h2>Baños en esta planta</h2>${near.map((x,i)=>itemHTML(EK['s:'+x.id],i,'')).join('')}</div>`}
    h+=`<div class="ut-sec"><h2>Edificio</h2>${itemHTML(EK['b:'+b.id],0,'')}</div>`}
  else if(t==='d'){const d=DP[id];selB=d.b;selS=d.space;fl=d.space?SP[d.space].floor:null;close=true;
    h+=head(B[d.b].code,AC(d.area),'Departamento académico','Departamento de '+d.name,esc(B[d.b].name)+(d.space?` · Planta ${SP[d.space].floor}`:''));
    h+=photo(d.img)+contact(d);h+=`<div class="ut-acts">${routeBtn()}<button class="ut-btn ut-sec2" data-k2="a:${d.area}">Ver ${esc(AN(d.area))}</button></div>`;
    const pf=D.PROFS.filter(p=>p.area===d.area);h+=`<div class="ut-sec"><h2><span>Docentes</span><span>${pf.length}</span></h2>${pf.slice(0,S.expanded.dp?99:5).map((p,i)=>itemHTML(EK['p:'+p.id],i,'')).join('')}${pf.length>5&&!S.expanded.dp?`<button class="ut-more" data-act="x:dp">Ver los ${pf.length} →</button>`:''}</div>`}
  else if(t==='m'){const s=SU[id];const cl=(bySubj[id]||[]).slice().sort((a,b)=>a.hour-b.hour);
    h+=head(AB(s.area),AC(s.area),'Materia · '+s.id,s.name,esc([AREAS[s.area]?.name,s.cred?s.cred+' créditos':null].filter(Boolean).join(' · ')));
    h+=`<div class="ut-facts">${fact('Bloques',cl.length)}${fact('Grupos',new Set(cl.map(c=>c.group)).size)}${fact('Profesores',new Set(cl.map(c=>c.prof).filter(Boolean)).size)}${fact('En clase ahora',cl.filter(liveAt).length)}</div>`;
    h+=`<div class="ut-sec"><h2><span>Grupos y horarios</span><span>${cl.length}</span></h2>${cl.map((c,i)=>clsItem(c,'subject',i)).join('')}</div>`;S.roomFocus=new Set(cl.map(c=>c.room).filter(Boolean))}
  else if(t==='g'){const g=GR[id];const cl=(byGroup[id]||[]).slice().sort((a,b)=>a.hour-b.hour);
    const gs0=SU[g.subject];h+=head(g.code,AC(g.area),'Grupo'+(g.turno?' · '+g.turno:''),`${gs0?.name||g.subject} · grupo ${g.code}`,esc([AREAS[g.area]?.name,g.career].filter(Boolean).join(' · ')));
    h+=`<div class="ut-facts">${fact('Bloques',cl.length)}${fact('Horas semana',cl.reduce((a,c)=>a+c.days.length*dur(c),0))}</div><div class="ut-acts"><button class="ut-btn" data-act="sched">Horario del grupo</button></div>`;
    h+=`<div class="ut-sec"><h2>Clases</h2>${cl.map((c,i)=>clsItem(c,'group',i)).join('')||'<div class="ut-hint">Sin clases asignadas en este periodo.</div>'}</div>`;S.roomFocus=new Set(cl.map(c=>c.room).filter(Boolean));openDrawer('group',id)}
  else if(t==='p'){const p=PR[id];const cl=(byProf[id]||[]).slice().sort((a,b)=>a.hour-b.hour);const c=cl.find(liveAt);selB=c&&c.room?bOfRoom(c.room):p.building;selS=c&&c.room?c.room:p.cub;fl=selS?SP[selS].floor:null;
    h+=head(e.badge,AC(p.area),'Docente · '+AN(p.area),pName(p),p.email?`<a href="mailto:${esc(p.email)}">${esc(p.email)}</a>`:esc(AREAS[p.area]?.name||''));
    h+=c?`<div class="ut-now ut-busy"><span class="ut-pulse"></span><div><b>Ahora en ${esc(rName(c))}</b>${rBld(c)?` (${esc(rBld(c).code)})`:''} · ${esc(sOf(c))} con ${esc(gOf(c))}</div></div>`:`<div class="ut-now ut-free"><span class="ut-pulse"></span><div><b>Sin clase ahora</b>${p.building||p.loc?' · '+esc([p.loc,B[p.building]?.name].filter(Boolean).join(', ')):''}</div></div>`;
    h+=`<div class="ut-facts">${fact('Departamento',esc(AN(p.area)))}${fact('Horas frente a grupo',cl.reduce((a,c)=>a+c.days.length*dur(c),0)+' h')}${fact('Grupos',new Set(cl.map(c=>c.group)).size)}${fact('Edificios',bids.length)}</div>`;
    h+=`<div class="ut-acts"><button class="ut-btn" data-act="sched">Horario semanal</button>${routeBtn()}</div>`;
    h+=`<div class="ut-sec"><h2><span>Clases</span><span>${cl.length}</span></h2>${cl.map((c,i)=>clsItem(c,'prof',i)).join('')}</div>`;openDrawer('prof',id)}
  else if(t==='a'){const a=AREAS[id];const rs=D.SPACES.filter(s=>s.owner===id);const pf=D.PROFS.filter(p=>p.area===id);const sj=D.SUBJECTS.filter(s=>s.area===id);const gs=D.GROUPS.filter(g=>g.area===id);const dp=D.DEPTS.filter(d=>d.area===id);
    h+=head(a.abbr,a.color,'Departamento académico',a.name,'');h+=`<div class="ut-facts">${fact('Edificios',bids.length)}${fact('Grupos',gs.length)}${fact('Docentes',pf.length)}${fact('Materias',sj.length)}</div>`;
    const sec=(title,arr,key,lim=4)=>arr.length?`<div class="ut-sec"><h2><span>${title}</span><span>${arr.length}</span></h2>${arr.slice(0,S.expanded[key]?99:lim).map((x,i)=>itemHTML(x,i,'',liveTag(x))).join('')}${arr.length>lim&&!S.expanded[key]?`<button class="ut-more" data-act="x:${key}">Ver los ${arr.length} →</button>`:''}</div>`:'';
    h+=sec('Oficina del departamento',dp.map(d=>EK['d:'+d.id]).filter(Boolean),'ad',2)+sec('Edificios',bids.map(b=>EK['b:'+b]),'ab')+sec('Salones y laboratorios',rs.map(r=>EK['s:'+r.id]).filter(Boolean),'ar')+sec('Materias',sj.map(s=>EK['m:'+s.id]).filter(Boolean),'as')+sec('Grupos',gs.map(g=>EK['g:'+g.id]).filter(Boolean),'ag')+sec('Docentes',pf.map(p=>EK['p:'+p.id]).filter(Boolean),'ap');S.roomFocus=new Set(rs.map(r=>r.id))}
  if(!['m','g','a'].includes(t))S.roomFocus=null;
  S.selRoom=selS;
  if(selB&&fl&&INT[selB]!==undefined||selB&&fl)openInterior(selB,fl);else if(S.open&&S.open!==selB)closeInterior();
  if(selB&&S.route)computeRoute(selB,selS);else computeRoute(null);
  if(selB&&S.route){h=h.replace(/Ocultar ruta(?: · ≈\d+ min \(\d+ m\))?/g,S.walk?`Ocultar ruta · ≈${S.walk} min (${S.walkM} m)`:'Ocultar ruta');
    if(S.steps){const ix=h.indexOf('data-act="route"');const end=h.indexOf('</div>',ix)+6;const acc=selS&&SP[selS]&&SP[selS].floor===2?`<button class="ut-more" data-act="access" style="padding:6px 0 0">${S.access?'✓ Ruta accesible (elevador)':'Usar ruta accesible (elevador)'}</button>`:'';
      const steps=`<ol class="ut-steps">${S.steps.map(x=>`<li>${esc(x)}</li>`).join('')}</ol>${acc}`;if(ix>0)h=h.slice(0,end)+steps+h.slice(end)}}
  pb.innerHTML=h;if(!keepCam)pb.scrollTop=0;
  pb.querySelectorAll('[data-k2]').forEach(x=>x.onclick=()=>open(x.dataset.k2));
  const fm=new Map();if(t!=='b'&&t!=='s'&&t!=='d'){const c=e?colorOf(e):'#092d48';bids.forEach(b=>fm.set(b,c))}HL.focus=fm;HL.sel=selB;buildRoomLabels();
  if(keepCam)return;
  if(selS&&SP[selS]){const s=SP[selS];fly(new THREE.Vector3(s.c[0],(s.floor-1)*FH,s.c[1]),MOB()?190:150)}else flyTo(selB&&!['m','a','g'].includes(t)?[selB]:bids,t==='b')}
function act(a,el){if(a==='back')return back();
  if(a==='fav'){const k=S.stack[S.stack.length-1];FAV=FAV.includes(k)?FAV.filter(x=>x!==k):[k,...FAV];LS.set('ubicatec.fav',FAV);toast(FAV.includes(k)?'Guardado en favoritos':'Quitado de favoritos');renderDetail(k,true);return}
  if(a==='share'){const url=location.href;(navigator.clipboard?.writeText(url)||Promise.reject()).then(()=>toast('Enlace copiado'),()=>toast('No se pudo copiar el enlace'));return}
  if(a==='route'){S.route=!S.route;renderDetail(S.stack[S.stack.length-1],true);return}
  if(a==='access'){S.access=!S.access;renderDetail(S.stack[S.stack.length-1],true);return}
  if(a==='floor'){setFloor(+el.dataset.f);return}
  if(a==='sched'){const k=S.stack[S.stack.length-1];openDrawer({s:'room',g:'group',p:'prof'}[k[0]],k.slice(2),true);return}
  if(a==='bsched'){openDrawer('building',S.stack[S.stack.length-1].slice(2),true);return}
  if(a.startsWith('x:')){S.expanded[a.slice(2)]=1;renderDetail(S.stack[S.stack.length-1],true)}}
function rerouteCurrent(){const k=S.stack[S.stack.length-1];if(k)renderDetail(k,true)}

// ---------- horario ----------
const drawer=$('#drawer'),sched=$('#sched');let dctx=null;
function closeDrawer(){drawer.classList.remove('ut-open');dctx=null}
$('#dwx').onclick=closeDrawer;
function openDrawer(mode,id,force){if(rootEl.clientWidth<=1100&&!force)return;let cl,title;
  if(mode==='room'){cl=byRoom[id]||[];title=`Horario semanal · ${SP[id].name} · ${B[SP[id].building].code}`}
  if(mode==='group'){cl=byGroup[id]||[];title=`Horario · ${SU[GR[id].subject]?.name||GR[id].subject} · grupo ${GR[id].code}`}
  if(mode==='prof'){cl=byProf[id]||[];title=`Horario · ${pName(PR[id])}`}
  const rooms=mode==='building'?spacesOf(id).filter(s=>s.sched):null;if(mode==='building'){cl=rooms.flatMap(r=>byRoom[r.id]||[]);title=`${B[id].name} · ${cl.length} clases`}
  dctx={mode,id};const areas=[...new Set(cl.map(c=>SU[c.subject]?.area).filter(a=>AREAS[a]))];$('#dwt').textContent=title;
  $('#dwl').innerHTML=areas.map(a=>`<span style="--c:${AC(a)};--on:${onC(AC(a))}"><i></i>${esc(AREAS[a].short)}</span>`).join('')+(MOB()?'':`<span style="margin-left:auto;color:var(--ink3)">Clic en una clase para ${mode==='room'?'ver al docente':'ver el salón'}</span>`);
  const ndL=Math.max(5,...cl.flatMap(c=>c.days.map(d=>d+1)));const h0=cl.length?Math.max(T0,Math.min(...cl.map(c=>c.hour))):T0,h1=cl.length?Math.min(T1,Math.max(...cl.map(c=>c.end-1))):T1;const HRS=D.HOURS.filter(x=>x>=h0&&x<=h1);const DL=(MOB()?D.DAYS:D.DAYS_LONG).slice(0,ndL);sched.style.setProperty('--nd',ndL);sched.style.setProperty('--nh',HRS.length);const row=hr=>hr-h0+2;let h='<div></div>'+DL.map((d,i)=>`<div class="ut-dh2${i===S.day?' ut-today':''}" style="grid-column:${i+2};grid-row:1">${d}</div>`).join('');
  HRS.forEach((hr,j)=>{h+=`<div class="ut-hr ut-mono" style="grid-column:1;grid-row:${j+2}">${hr}:00</div>`;for(let d=0;d<ndL;d++)h+=`<div class="ut-bg${d===S.day&&hr===S.hour?' ut-nowc':''}" style="grid-column:${d+2};grid-row:${j+2}"></div>`});
  if(mode==='building'){const cell={};cl.forEach(c=>c.days.forEach(d=>{for(let hh=c.hour;hh<c.end;hh++)(cell[d+'-'+hh]??=[]).push(c)}));for(const key in cell){const [d,hr]=key.split('-').map(Number);if(hr<h0||hr>h1)continue;const arr=cell[key];const n=new Set(arr.map(c=>c.room)).size,tot=rooms.length;h+=`<div role="button" tabindex="0" class="ut-cls" data-cl="${arr[0].id}" style="--c:${AC(SU[arr[0].subject]?.area)};--on:${onC(AC(SU[arr[0].subject]?.area))};grid-column:${d+2};grid-row:${row(hr)};opacity:${.45+.55*Math.min(1,n/tot)}"><b>${n} de ${tot} ocupados</b><span>${[...new Set(arr.map(rRaw))].slice(0,2).join(', ')}${n>2?'…':''}</span></div>`}}
  else cl.forEach((c,ci)=>{const s=SU[c.subject];c.days.forEach(d=>{const l2=mode==='room'?`${gOf(c)} · ${pOf(c)}`:mode==='prof'?`${rRaw(c)} · ${gOf(c)}`:`${rRaw(c)} · ${pOf(c)}`;
    h+=`<div role="button" tabindex="0" class="ut-cls${liveAt(c)&&d===S.day?' ut-live':''}" data-cl="${c.id}" title="${esc(sOf(c))} — ${esc(gOf(c))} — ${esc(pOf(c))} — ${esc(rName(c))}" style="--c:${AC(s?.area)};--on:${onC(AC(s?.area))};grid-column:${d+2};grid-row:${row(c.hour)}/${row(Math.min(c.end,h1+1))};animation-delay:${(ci*5+d)*12}ms"><b>${esc(sOf(c))}</b><span>${esc(l2)}</span></div>`})});
  sched.innerHTML=h;drawer.classList.add('ut-open')}
sched.addEventListener('click',e=>{const el=e.target.closest('[data-cl]');if(!el||!dctx)return;const c=D.CLASSES[+el.dataset.cl.slice(1)];const k=dctx.mode==='room'?(c.prof?'p:'+c.prof:''):(c.room?'s:'+c.room:'m:'+c.subject);if(!EK[k])return;if(MOB())closeDrawer();open(k)});
sched.addEventListener('mouseover',e=>{const el=e.target.closest('[data-cl]');if(!el){HL.hoverSet=null;return}const c=D.CLASSES[+el.dataset.cl.slice(1)];const bb=bOfRoom(c.room);HL.hoverSet=bb?new Set([bb]):null;HL.hoverRoom=c.room});
sched.addEventListener('mouseleave',()=>{HL.hoverSet=null;HL.hoverRoom=null});
sched.addEventListener('keydown',kbAct);

// ---------- ocupación / noche ----------
const oday=$('#oday'),ohour=$('#ohour');ohour.min=T0;ohour.max=T1;oday.innerHTML=D.DAYS_LONG.map((d,i)=>`<option value="${i}">${d}</option>`).join('');oday.value=S.day;ohour.value=S.hour;$('#ohl').textContent=S.hour+':00';
const updOcc=()=>{for(const id in BLD){const o=occOf(id);LBL[id].querySelector('.ut-occ').textContent=o==null||o===0?'':Math.round(o*100)+'%'}};
const updTime=()=>{S.day=+oday.value;S.hour=+ohour.value;$('#ohl').textContent=S.hour+':00';updOcc();if(S.stack.length)renderDetail(S.stack[S.stack.length-1],true);else render()};
oday.onchange=updTime;ohour.oninput=updTime;
$('#vocc').onclick=()=>{S.occ=!S.occ;rootEl.classList.toggle('ut-occ',S.occ);$('#vocc').classList.toggle('ut-on',S.occ);$('#vocc').setAttribute('aria-pressed',S.occ);updOcc()};
if(!D.CLASSES.length){const b=$('#vocc');b.disabled=true;b.title='Sin clases en este periodo'}
$('#compass').onclick=()=>{const t=controls.target.clone();const d=camera.position.distanceTo(t);const pol=controls.getPolarAngle();flyRaw(new THREE.Vector3(t.x,t.y+d*Math.cos(pol),t.z+d*Math.sin(pol)),t,700)};
const lights=scene.children.filter(o=>o.isLight);const DAYL=lights.map(l=>l.intensity);
function setNight(on){S.night=on;$('#vnight').classList.toggle('ut-on',on);$('#vnight').setAttribute('aria-pressed',on);rootEl.classList.toggle('ut-night',on);stage.style.setProperty('--stage-bg',on?'#151821':'#E9E4DC');
  lights.forEach((l,i)=>{l.intensity=DAYL[i]*(on?(l.isHemisphereLight?.35:.22):1);if(l.isHemisphereLight)l.color.set(on?'#8FA3D9':'#ffffff')});
  MT.vidrio.emissive.set(on?'#FFC46B':'#000000');MT.vidrio.emissiveIntensity=on?1.1:0;MT.azotea.color.set(on?'#9A9488':'#E2D9CC');dotM.color.set(on?'#7FD3FF':'#092d48');pinM.emissive.set(on?'#2B5C9A':'#000000')}
$('#vnight').onclick=()=>setNight(!S.night);

// ---------- hoja inferior (móvil) ----------
const SNAP={peek:()=>panel.clientHeight-150,half:()=>panel.clientHeight*.5,full:()=>0};let sheet='peek';
function snap(s){if(!MOB())return;sheet=s;panel.style.setProperty('--sy',SNAP[s]()+'px');rootEl.style.setProperty('--peek',(panel.clientHeight-SNAP[s]())+'px')}
{let y0=null,s0=0,moved=false;const ph=panel.querySelector('.ut-ph');
  ph.addEventListener('pointerdown',e=>{if(!MOB()||e.target.closest('input,button'))return;y0=e.clientY;s0=SNAP[sheet]();moved=false;panel.classList.add('ut-drag');ph.setPointerCapture(e.pointerId)});
  ph.addEventListener('pointermove',e=>{if(y0==null)return;const dy=e.clientY-y0;if(Math.abs(dy)>4)moved=true;panel.style.setProperty('--sy',Math.max(0,Math.min(SNAP.peek(),s0+dy))+'px')});
  ph.addEventListener('pointerup',e=>{if(y0==null)return;panel.classList.remove('ut-drag');const cur=s0+(e.clientY-y0);y0=null;if(!moved){snap(sheet==='peek'?'half':sheet==='half'?'full':'half');return}
    const c=['full','half','peek'].map(k=>[k,Math.abs(SNAP[k]()-cur)]).sort((a,b)=>a[1]-b[1])[0][0];snap(c)})}
const rootRO=new ResizeObserver(()=>{if(MOB())snap(sheet);else panel.style.removeProperty('--sy')});rootRO.observe(rootEl);
snap('peek');

// ---------- origen ----------
pb.addEventListener('click',e=>{if(e.target.closest('[data-act="origin"]')){S.picking=true;rootEl.classList.add('ut-picking');if(MOB())snap('peek')}});
const _routeBtn=routeBtn;

// ---------- arranque ----------
const dec=x=>{try{return decodeURIComponent(x)}catch(e){return''}};const hk=dec(location.hash.slice(1))||rootEl.dataset.initial||'';if(hk&&EK[hk]){S.stack=[hk];render()}else render();
addEventListener('hashchange',()=>{const k=dec(location.hash.slice(1));if(EK[k]&&S.stack[S.stack.length-1]!==k)open(k)},sig);
// Liberación: corta el bucle, los oyentes de window y el renderer (un contexto WebGL por visita no se puede acumular).
function destroy(){if(!alive)return;alive=false;cancelAnimationFrame(rafId);ac.abort();rootRO.disconnect();stage.removeEventListener('ubicatec:stage-dispose',destroy);if(window.UbicaTec===api)delete window.UbicaTec;stage.dispose?.()}
stage.addEventListener('ubicatec:stage-dispose',destroy);
// Sonda para las pruebas de navegador: abre el edificio del salón y da dónde está en pantalla (coordenadas de cliente).
function probe(key){const sid=KEY2SID[key],s=sid&&SP[sid];if(!s)return null;if(S.open!==s.building||S.floor!==s.floor)openInterior(s.building,s.floor);
  camera.updateMatrixWorld();const v=new THREE.Vector3(s.c[0],(s.floor-1)*FH+1.9,s.c[1]).project(camera);const r=stage.getBoundingClientRect();return{x:Math.round(r.left+(v.x*.5+.5)*r.width),y:Math.round(r.top+(-v.y*.5+.5)*r.height)}}
const api={state:S,data:D,open,keys:()=>Object.keys(EK),enablePick,disablePick,probe,destroy};
window.UbicaTec=api;
addEventListener('ubicatec:focus',e=>{const k=e.detail?.key;if(k&&EK[k])open(k)},sig);
rootEl.classList.add('ut-ready');
window.dispatchEvent(new CustomEvent('ubicatec:ready'));
return api;
}
