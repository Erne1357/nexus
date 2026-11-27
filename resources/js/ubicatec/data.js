// Geometría real (GeoJSON de ubicaTec) + datos académicos reales que manda el servidor (#ubicatec-data, CampusMapQuery).
export const norm=s=>(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');

// Estilo por clave de departamento (color, sigla y palabras de búsqueda). Las claves sin estilo usan uno neutro.
const DEPT_STYLE={
  '110100':{short:'Ciencias Básicas',abbr:'CB',color:'#4A8B37',alias:'ciencias basicas matematicas fisica quimica cb'},
  '110200':{short:'Metal Mecánica',abbr:'MM',color:'#4F6475',alias:'mecanica metal mecanica manufactura mec'},
  '110300':{short:'Industrial',abbr:'II',color:'#D9861A',alias:'industrial ind logistica'},
  '110303':{short:'Industrial',abbr:'II',color:'#8A6D1F',alias:'industrial ind logistica'},
  '110500':{short:'Eléctrica y Electrónica',abbr:'EE',color:'#C6A019',alias:'electrica electronica ele elc electromecanica'},
  '110600':{short:'Sistemas',abbr:'SC',color:'#2F6DB5',alias:'sistemas computacion computacionales isc software programacion'},
  '110700':{short:'Económico-Adm.',abbr:'CEA',color:'#B2437A',alias:'economico administrativo contador contaduria administracion cea gestion empresarial'},
  '110900':{short:'Posgrado',abbr:'PI',color:'#5A3D2B',alias:'posgrado maestria doctorado investigacion'},
  '111200':{short:'Mecatrónica',abbr:'MT',color:'#D5502F',alias:'mecatronica mct robotica'},
  '111300':{short:'Logística',abbr:'LG',color:'#8A6D1F',alias:'logistica log cadena suministro'},
  '112300':{short:'Empresarial',abbr:'IE',color:'#22968A',alias:'empresarial gestion ige'},
};
const NEUTRAL={color:'#7A6F66',alias:''};
// Claves de área del BINFO viejo -> claves de departamento.
const OLD_AREA={ISC:['110600'],IND:['110300'],LOG:['111300'],ELE:['110500'],ELC:['110500'],MEC:['110200'],MCT:['111200'],IGE:['112300'],CEA:['110700'],CB:['110100'],POS:['110900']};
export const KINDS={0:'Edificio',2:'Escalera',3:'Salón',4:'Pasillo',5:'Baños',6:'Oficina',7:'Estacionamiento',8:'Baños hombres',9:'Baños mujeres',10:'Elevador',11:'Punto de venta',12:'Laboratorio',13:'Área de descanso',14:'Bodega',15:'Cancha'};
export const KIND_COLORS={2:'#9BC48A',3:'#F0E1CB',4:'#EEE8DF',5:'#B7B0D9',6:'#DE9A8C',7:'#B9B9B9',8:'#8FB8DE',9:'#E3A6C9',10:'#7FA7A0',11:'#BB8FCE',12:'#EFA058',13:'#F6C96B',14:'#D8CFB8',15:'#7FB069'};
export const KIND_GLYPH={2:'↕',10:'⇅',8:'WC♂',9:'WC♀',5:'WC'};

let BASE='/ubicatec/',IMG=BASE+'img/';
export function setBase(b){BASE=b.endsWith('/')?b:b+'/';IMG=BASE+'img/'}
// Datos de LocationSeeder / LegacyInfoSeeder de ubicaTec, por nombre de polígono en piso0.
export const BINFO={
  'Edificio Rivera Lara':{code:'RL',cat:'Salones',areas:['ISC','IND','IGE','CEA','LOG','MCT','ELC'],img:'rivera_lara.webp',desc:'Edificio principal del instituto. En él se encuentran la Sala de Alumnos, la Liebre Shop, el Consultorio Médico y la Sala de Descanso, además de aulas y oficinas en sus tres pisos.'},
  'Edificio Sistemas':{code:'SC',cat:'Departamentos',areas:['ISC'],img:'sistemas.webp',desc:'Edificio de Sistemas Computacionales, con los salones de la serie 700, oficinas de maestros y el departamento de Sistemas.',dept:'Sistemas Computacionales'},
  'Ciencias basicas':{display:'Ciencias Básicas',code:'CB',cat:'Departamentos',areas:['CB'],img:'ciencias-basicas.webp',desc:'Edificio de Ciencias Básicas, donde se imparten las materias comunes a todas las carreras del instituto.',dept:'Ciencias Básicas'},
  'Edificio Eléctrica':{code:'EE',cat:'Departamentos',areas:['ELE','ELC'],img:'electro.webp',desc:'Edificio de laboratorios de Eléctrica y Electrónica. Cuenta con el Laboratorio de Energías Alternativas, laboratorio de simulación y áreas de máquinas y control.',dept:'Eléctrica y Electrónica'},
  'Edificio Metal Mecánica':{code:'MM',cat:'Departamentos',areas:['MEC'],img:'metalMecanica.webp',desc:'Edificio de talleres y laboratorios de Metal Mecánica: área de máquinas, instrumentación, materiales e ingeniería térmica.',dept:'Metal Mecánica'},
  'Edificio T - Ingeniería Industrial':{code:'T',cat:'Departamentos',areas:['IND'],img:'industrial.webp',desc:'Edificio T, con los salones de la serie 900 usados principalmente por Ingeniería Industrial, además de cubículos y secretaría.',dept:'Industrial y Logística'},
  "Edificio P - 800's":{code:'P',cat:'Salones',areas:['IND','LOG'],img:'800s.webp',desc:'Edificio con las aulas de la serie 800, usadas principalmente por las carreras de Ingeniería Industrial y Logística.'},
  'Edificio Q - Editorial':{code:'Q',cat:'Administrativo',areas:[],desc:'Edificio Q, sede de la editorial del instituto y de oficinas administrativas como Compras y Activo Fijo.'},
  'Edificio V - Centro de idiomas':{code:'V',cat:'Salones',areas:['IDI'],img:'centro_idiomas.webp',desc:'Centro de idiomas del instituto, donde los alumnos toman cursos de idiomas extranjeros.'},
  'Edificio W - Posgrado':{code:'W',cat:'Salones',areas:['POS','IND','CEA'],img:'posgrado.webp',desc:'Edificio de posgrado, donde se imparten las clases de maestría y, en ocasiones, clases regulares de licenciatura.'},
  'Edificio Y - Laboratorio de Mecatronica':{display:'Edificio Y - Laboratorio de Mecatrónica',code:'Y',cat:'Laboratorios',areas:['MCT'],img:'meca.webp',desc:'Laboratorio con equipo especializado para prácticas de Mecatrónica: robótica, inteligencia artificial, IoT, impresión 3D y manufactura avanzada.'},
  'Laboratorio de manufactura':{display:'Laboratorio de Manufactura',code:'LM',cat:'Laboratorios',areas:['MEC','IND'],img:'manufactura.webp',desc:'Laboratorio donde los alumnos realizan sus prácticas de manufactura. Cuenta con áreas de automatización, metrología, carpintería y máquinas-herramienta.'},
  'Aulas 400/Audiovisual':{code:'400',cat:'Salones',areas:['CEA','IGE'],img:'400s.webp',desc:'Edificio con las aulas de la serie 400 y el auditorio Audiovisual, donde se imparten clases y se proyectan eventos académicos.'},
  'Centro de cómputo/Lab de contabilidad':{code:'CC',cat:'Laboratorios',areas:['ISC','CEA'],img:'centro_comp.webp',desc:'Centro de cómputo donde alumnos y maestros pueden recuperar su usuario y contraseña institucionales y resolver asuntos de cómputo. A un lado se encuentra el laboratorio especializado para los alumnos de Contaduría.'},
  'División de estudios':{display:'División de Estudios',code:'DE',cat:'Administrativo',areas:[],img:'div_estudios.webp',desc:'Edificio donde se encuentran los coordinadores de cada carrera y la coordinación de la división, además de oficinas de vinculación, investigación y proyectos docentes.'},
  'Edificio Guillot':{code:'G',cat:'Servicios',areas:[],img:'gillot.webp',desc:'Edificio donde los alumnos acuden a asesorías, tutorías y trámites de titulación y residencias. Alberga el Laboratorio de Alta Tecnología (LAT), la incubadora de empresas y varias salas de titulación.'},
  'Edificio Administración':{code:'AD',cat:'Administrativo',areas:[],desc:'Edificio administrativo del instituto. Aquí se encuentran Servicios Escolares, Recursos Humanos, Recursos Financieros y Caja, así como la Dirección y las subdirecciones Académica, Administrativa y de Planeación y Vinculación.'},
  'Centro de Información':{display:'Centro de Información (Biblioteca)',code:'CI',cat:'Servicios',areas:[],img:'Biblioteca.webp',desc:'Edificio del Centro de Información (Biblioteca), con acervo bibliográfico, salas de estudio, cubículos y equipo de cómputo.',phone:'(656) 688-2500 Ext. 2181',syn:'biblioteca ci'},
  'Gimnasio':{code:'GM',cat:'Deportes',areas:[],img:'gym.webp',desc:'Gimnasio del instituto, usado para clases de educación física y eventos deportivos.',phone:'(656) 688-2500 Ext. 2161',syn:'gym',h:15},
  'Alberca':{code:'AL',cat:'Deportes',areas:[],img:'Alberca.webp',desc:'Alberca del instituto, usada para clases de natación y actividades deportivas.',phone:'+52 (656) 7054568',syn:'piscina',h:10},
  'Auditorio Multifuncional':{code:'AM',cat:'Servicios',areas:[],img:'multi.webp',desc:'Auditorio donde se realizan conferencias, ceremonias y eventos especiales del instituto. Incluye el auditorio principal, uno secundario, la sala de ajedrez y la jefatura de Extraescolares.',h:13},
  'Nodo':{display:'Nodo de Creatividad',code:'N',cat:'Servicios',areas:['IGE'],img:'nodo.webp',desc:'Espacio de innovación donde los estudiantes experimentan con herramientas y tecnología de punta.'},
  'Oficinas de mantenimiento':{display:'Oficinas de Mantenimiento',code:'MT',cat:'Servicios',areas:[],img:'mantenimiento.webp',desc:'Oficinas donde se solicitan reparaciones y mantenimiento del equipo e instalaciones del instituto. Incluye talleres de carpintería y mantenimiento, y el área de jardinería.'},
  'Cafeteria':{display:'Cafetería',code:'CF',cat:'Comida',areas:[],img:'Cafeteria.webp',desc:'Cafetería principal del instituto, donde los estudiantes pueden comer entre clases.',syn:'comedor comida'},
  'Coffee Shop':{code:'CS',cat:'Comida',areas:[],img:'Coffee-Shop.webp',desc:'Puesto de café frente al gimnasio, donde puedes comprar café, matcha, paninis y otros snacks.',phone:'+52 (656) 3308840',syn:'cafe comida',h:4},
};
const SPACE_INFO={'Consultorio Médico':{img:'consultorio-medico.webp',desc:'Consultorio médico y de enfermería para atención de primeros auxilios a la comunidad estudiantil.',syn:'doctor enfermeria'},'Liebre Shop':{img:'liebre-shop.webp',desc:'Tienda escolar donde se venden artículos, uniformes y papelería del instituto.',syn:'tienda papeleria uniformes'},'Sala de alumnos':{desc:'Espacio de descanso y convivencia para los estudiantes entre clases.'}};
// ---- proyección lat/lng → metros (x este, z sur) ----
const LAT0=31.719543,LNG0=-106.421424,KX=111320*Math.cos(LAT0*Math.PI/180),KZ=110540;
export const proj=([lng,lat])=>[(lng-LNG0)*KX,-(lat-LAT0)*KZ];
const centroid=p=>{let a=0,cx=0,cz=0;for(let i=0,j=p.length-1;i<p.length;j=i++){const f=p[j][0]*p[i][1]-p[i][0]*p[j][1];a+=f;cx+=(p[j][0]+p[i][0])*f;cz+=(p[j][1]+p[i][1])*f}if(Math.abs(a)<1e-9){const n=p.length;return[p.reduce((s,q)=>s+q[0],0)/n,p.reduce((s,q)=>s+q[1],0)/n]}a*=.5;return[cx/(6*a),cz/(6*a)]};
const flat=(c,o=[])=>{for(const f of c.features||[]){if(f.type==='FeatureCollection')flat(f,o);else o.push(f)}return o};


const DEPT_IMG={'110700':'eco-admin.webp'};
const EMPTY={term:null,source:null,buildings:{},rooms:{},departments:[],subjects:[],groups:[],profs:[],classes:[]};
// Datos académicos del servidor: JSON embebido en la página (sin API). Si falta o está dañado, el mapa sigue con la geometría.
export function readServer(){try{const el=document.getElementById('ubicatec-data');return el?{...EMPTY,...JSON.parse(el.textContent||'{}')}:EMPTY}catch(e){return EMPTY}}

export async function load(srv=readServer()){
  const [geo,acc]=await Promise.all([Promise.all([0,1,2].map(n=>fetch(`${BASE}geo/piso${n}.json`).then(r=>r.json()))),fetch(`${BASE}geo/accesos.json`).then(r=>r.json())]);
  // ---- departamentos (= áreas) ----
  const AREAS={};
  const addArea=(id,name)=>{if(!id||AREAS[id])return;const st=DEPT_STYLE[id]||{};AREAS[id]={name:name||'Departamento '+id,short:st.short||name||id,abbr:st.abbr||'·',color:(st.color||NEUTRAL.color),alias:norm((st.alias||'')+' '+(name||''))}};
  srv.departments.forEach(d=>addArea(d.id,d.name));
  [...srv.subjects,...srv.groups,...srv.profs].forEach(x=>{if(x.area&&!AREAS[x.area])addArea(x.area,null)});
  const areaKeys=a=>(OLD_AREA[a]||[]).filter(k=>AREAS[k]);
  const BUILDINGS=[],GROUNDS=[],SPACES=[];
  flat(geo[0]).forEach((f,i)=>{const p=f.properties;const poly=f.geometry.coordinates[0].map(proj);poly.pop();const c=centroid(poly);
    if(p.kind===0){const base=BINFO[p.name]||{code:p.name.slice(0,2).toUpperCase(),areas:[],cat:'Servicios'};const sv=srv.buildings[p.name]||{};
      const info={...base,areas:[...new Set((base.areas||[]).flatMap(areaKeys))]};
      BUILDINGS.push({id:p.name,name:sv.name||info.display||p.name,poly,c,...info,code:sv.code||info.code,img:info.img?IMG+info.img:null,floors:1});}
    else GROUNDS.push({id:'g'+i,name:p.name,kind:p.kind,poly,c});});
  const BK=Object.fromEntries(BUILDINGS.map(b=>[norm(b.id),b]));
  const SPKEY={};
  [1,2].forEach(fl=>flat(geo[fl]).forEach((f,i)=>{const p=f.properties;const b=BK[norm(p.building)];if(!b)return;
    const poly=f.geometry.coordinates[0].map(proj);poly.pop();
    const name=p.kind===3&&/^\d+$/.test(p.name)?'Salón '+p.name:p.name;
    if(fl===2)b.floors=2;
    const key=`${b.id}|${p.name}`;const sv=srv.rooms[key]||null;
    const sp={id:`${b.code}-${fl}-${i}`,name:sv?.name||name,raw:p.name,kind:p.kind,building:b.id,floor:fl,poly,c:centroid(poly),...(SPACE_INFO[p.name]||{}),img:SPACE_INFO[p.name]?.img?IMG+SPACE_INFO[p.name].img:null};
    // Solo lo que dice el servidor: sin datos no hay dueño, capacidad ni horario.
    if(sv){sp.sched=true;sp.code=sv.code||null;sp.type=sv.type||null;sp.cap=sv.capacity||null;sp.owner=sv.owner&&AREAS[sv.owner]?sv.owner:null}
    if(!(key in SPKEY))SPKEY[key]=sp.id;SPACES.push(sp);}));
  // Correcciones de orientación: el edificio se gira completo (contorno, interiores y accesos) sobre su centro
  const ROT_FIX={'Edificio Guillot':180};
  for(const [bn,deg] of Object.entries(ROT_FIX)){const b=BK[norm(bn)];if(!b)continue;const a=deg*Math.PI/180,ca=Math.cos(a),sa=Math.sin(a),[ox,oz]=b.c;
    const R=([x,z])=>[ox+(x-ox)*ca-(z-oz)*sa,oz+(x-ox)*sa+(z-oz)*ca];
    b.poly=b.poly.map(R);SPACES.filter(s=>s.building===b.id).forEach(s=>{s.poly=s.poly.map(R);s.c=R(s.c)});
    acc.doors.filter(d=>d.b===b.id).forEach(d=>{d.c=R(d.c)});}
  // ---- materias, grupos, docentes y clases ----
  const SUBJECTS=srv.subjects.map(s=>({id:s.id,name:s.name,area:s.area&&AREAS[s.area]?s.area:null,cred:s.cred??null}));
  const GROUPS=srv.groups.map(g=>({id:g.id,subject:g.subject,code:g.code,area:g.area&&AREAS[g.area]?g.area:null,career:g.career||null,turno:g.turno||null}));
  // ---- departamentos con oficina: solo los que tienen edificio se ubican en el mapa ----
  const DEPTS=srv.departments.filter(d=>d.b&&BK[norm(d.b)]).map(d=>{const bb=BK[norm(d.b)];const sp=SPACES.find(s=>s.building===bb.id&&(s.raw===d.room||s.name===d.room));
    return{id:d.id,name:d.name,area:d.id,b:bb.id,room:d.room,location:d.location||null,phone:d.phone||null,email:d.email||null,web:d.web||null,space:sp?.id||null,img:DEPT_IMG[d.id]?IMG+DEPT_IMG[d.id]:(bb.img||null)}});
  const DB=Object.fromEntries(DEPTS.map(d=>[d.area,d]));
  const PROFS=srv.profs.map(p=>{const d=DB[p.area];return{id:p.id,name:p.name,area:p.area&&AREAS[p.area]?p.area:null,email:p.email||null,building:d?.b||null,cub:d?.space||null,loc:d?(d.location||d.room||null):null}});
  const DAYS_ALL=['Lun','Mar','Mié','Jue','Vie','Sáb'],LONG_ALL=['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
  const nd=Math.max(5,...srv.classes.flatMap(c=>c.days.map(d=>d+1)));
  const h0=Math.min(7,...srv.classes.map(c=>c.hour)),h1=Math.max(21,...srv.classes.map(c=>c.end));
  const CLASSES=srv.classes.map((c,i)=>({id:'C'+i,subject:c.subject,group:c.group,prof:c.prof||null,room:c.room&&SPKEY[c.room]||null,roomLabel:c.roomLabel||null,days:c.days,hour:c.hour,end:Math.max(c.end||0,c.hour+1)}));
  const HOURS=Array.from({length:h1-h0},(_,i)=>h0+i);
  const start=GROUNDS.find(g=>/principal/i.test(g.name));
  return {SRV:srv,AREAS,WALKS:acc.walks,PLAZAS:acc.plazas,DOORS:acc.doors,GREENS:acc.greens,BUILDINGS,GROUNDS,SPACES,SUBJECTS,GROUPS,PROFS,CLASSES,DEPTS,DAYS:DAYS_ALL.slice(0,nd),DAYS_LONG:LONG_ALL.slice(0,nd),HOURS,START:start?start.c:[0,0]};
}
