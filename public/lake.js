(()=>{const h=document.documentElement,L=document.getElementById('lake');if(!L)return;
const on=()=>!h.classList.contains('dark')&&!h.classList.contains('phpstorm')&&!h.classList.contains('mode-night')&&(h.classList.contains('mode-day')||h.classList.contains('light')||!matchMedia('(prefers-color-scheme: dark)').matches);
const R=Math.random,HZ=560,SX=500;
let rays='';for(let i=0;i<22;i++){const a=(-178+i*8.3+R()*3)*Math.PI/180,w=.012+R()*.03,D=1500;
rays+=`<polygon class="lake-ray" style="animation-delay:${-R()*6}s" points="${SX},${HZ} ${SX+Math.cos(a-w)*D},${HZ+Math.sin(a-w)*D} ${SX+Math.cos(a+w)*D},${HZ+Math.sin(a+w)*D}" fill="url(#ray)"/>`}
const pine=(x,y,hh,c)=>{const w=hh*.28;let p=`<rect x="${x-hh*.02}" y="${y-hh*.15}" width="${hh*.04}" height="${hh*.15}" fill="${c}"/>`;
for(let k=0;k<4;k++){const top=y-hh+k*hh*.2,bot=y-hh*.1-(3-k)*hh*.16+k*hh*.02,ww=w*(.45+k*.22);p+=`<polygon points="${x},${top} ${x-ww},${bot} ${x+ww},${bot}" fill="${c}"/>`}return p};
const row=(x0,x1,y,n,c,hmin,hmax)=>{let t='';for(let i=0;i<n;i++){const x=x0+(x1-x0)*i/(n-1)+R()*10-5;t+=pine(x,y+R()*4,hmin+R()*(hmax-hmin),c)}return t};
const far=row(0,1000,HZ+2,90,'#7d86a8',18,42),mid=row(0,1000,HZ+4,60,'#46606a',35,80);
const leftShore=`<path d="M0 ${HZ-10} Q180 ${HZ-20} 340 ${HZ+30} L340 ${HZ+60} L0 ${HZ+80}Z" fill="#183128"/>`+row(-20,320,HZ+40,11,'#183128',150,300);
const rightShore=`<path d="M1000 ${HZ-10} Q820 ${HZ-20} 660 ${HZ+30} L660 ${HZ+60} L1000 ${HZ+80}Z" fill="#183128"/>`+row(680,1020,HZ+40,11,'#183128',150,300);
let glints='';for(let i=0;i<34;i++){const y=HZ+6+i*i*.33,w=(90-i*1.8)*(.6+R()*.8);glints+=`<rect class="lake-glint" style="animation-delay:${-R()*3}s" x="${SX-w/2+R()*20-10}" y="${y}" width="${w}" height="${2+i/10}" rx="2" fill="#fff4c8"/>`}
let clouds='';for(let i=0;i<7;i++){const cx=R()*1000,cy=150+R()*300,rx=80+R()*160;clouds+=`<ellipse cx="${cx}" cy="${cy}" rx="${rx}" ry="${10+R()*16}" fill="url(#cloud)" opacity="${.5+R()*.4}"/>`}
L.innerHTML=`<svg viewBox="0 0 1000 1000" preserveAspectRatio="xMidYMid slice"><defs>
<linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3f6fb0"/><stop offset=".28" stop-color="#9b9bd0"/><stop offset=".45" stop-color="#f3b3a0"/><stop offset=".56" stop-color="#ffd08a"/></linearGradient>
<radialGradient id="sun"><stop offset="0" stop-color="#fffef5"/><stop offset=".12" stop-color="#fff4c4"/><stop offset=".35" stop-color="#ffd890" stop-opacity=".7"/><stop offset="1" stop-color="#ffb27a" stop-opacity="0"/></radialGradient>
<radialGradient id="cloud"><stop offset="0" stop-color="#ffe3c8"/><stop offset="1" stop-color="#f7b8b0" stop-opacity="0"/></radialGradient>
<linearGradient id="ray" gradientUnits="userSpaceOnUse" x1="${SX}" y1="${HZ}" x2="${SX}" y2="0"><stop offset="0" stop-color="#fffbe6" stop-opacity=".95"/><stop offset="1" stop-color="#fffbe6" stop-opacity="0"/></linearGradient>
<linearGradient id="water" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f6c28e"/><stop offset=".25" stop-color="#9aa6c4"/><stop offset="1" stop-color="#1f3552"/></linearGradient>
<filter id="blur"><feGaussianBlur stdDeviation="6"/></filter><filter id="blur2"><feGaussianBlur stdDeviation="14"/></filter>
</defs><rect width="1000" height="1000" fill="url(#sky)"/>${clouds}
<g class="lake-rays" filter="url(#blur)">${rays}</g>
<circle cx="${SX}" cy="${HZ}" r="380" fill="url(#sun)"/><circle cx="${SX}" cy="${HZ}" r="52" fill="#fffef2"/>
<path d="M0 ${HZ} Q150 ${HZ-60} 300 ${HZ-25} T620 ${HZ-40} T1000 ${HZ-20} V${HZ+5} H0Z" fill="#a59bc0" opacity=".8"/>
${far}${mid}
<rect y="${HZ+4}" width="1000" height="${1000-HZ}" fill="url(#water)"/>
<g opacity=".3" transform="translate(0,${2*HZ+12}) scale(1,-1)">${mid}</g>
<ellipse cx="${SX}" cy="${HZ+8}" rx="160" ry="10" fill="#fff3c4" opacity=".9" filter="url(#blur)"/>${glints}
<rect x="0" y="${HZ-20}" width="1000" height="40" fill="#fff" opacity=".35" filter="url(#blur2)"/>
${leftShore}${rightShore}
<g opacity=".35" transform="translate(0,${2*HZ+80}) scale(1,-1)">${row(-20,320,HZ+40,11,'#0f2219',150,300)}${row(680,1020,HZ+40,11,'#0f2219',150,300)}</g>
</svg><canvas></canvas>`;
const cv=L.querySelector('canvas'),x=cv.getContext('2d');let W,H;const rs=()=>{W=cv.width=innerWidth;H=cv.height=innerHeight};rs();addEventListener('resize',rs);
const bugs=[],birds=[],spark=[];const bug=()=>({x:R()*W,y:H*.15+R()*H*.45,t:R()*9});
for(let i=0;i<16;i++)bugs.push(bug());for(let i=0;i<4;i++)birds.push({x:R()*W,y:R()*H*.5,vx:0,vy:0,f:R()*9});
const tick=()=>{requestAnimationFrame(tick);if(!h.classList.contains('scene'))return;x.clearRect(0,0,W,H);
bugs.forEach(b=>{b.t+=.04;b.x+=Math.sin(b.t*1.3)*1.4;b.y+=Math.cos(b.t*1.7)*1.1;const g=x.createRadialGradient(b.x,b.y,0,b.x,b.y,10);g.addColorStop(0,'rgba(255,250,170,.9)');g.addColorStop(1,'rgba(255,230,120,0)');
x.fillStyle=g;x.beginPath();x.arc(b.x,b.y,10,0,7);x.fill();x.fillStyle='#4a3410';x.beginPath();x.ellipse(b.x,b.y,3,2,0,0,7);x.fill();
x.fillStyle='rgba(255,255,255,.75)';const fw=Math.sin(b.t*40)*1.5;x.beginPath();x.ellipse(b.x-2,b.y-3,3,1.4+fw*.3,-.5,0,7);x.ellipse(b.x+2,b.y-3,3,1.4-fw*.3,.5,0,7);x.fill()});
birds.forEach(B=>{let t=null,d=1e9;bugs.forEach(b=>{const e=Math.hypot(b.x-B.x,b.y-B.y);if(e<d){d=e;t=b}});
if(t){B.vx+=(t.x-B.x)/d*.22;B.vy+=(t.y-B.y)/d*.22;if(d<14){bugs.splice(bugs.indexOf(t),1);for(let i=0;i<12;i++)spark.push({x:t.x,y:t.y,vx:R()*5-2.5,vy:R()*5-2.5,l:35});setTimeout(()=>bugs.push(bug()),1500)}}
const s=Math.hypot(B.vx,B.vy);if(s>4){B.vx*=4/s;B.vy*=4/s}B.x+=B.vx;B.y+=B.vy;B.f+=.25;
const a=Math.atan2(B.vy,B.vx),fl=Math.sin(B.f)*14;x.save();x.translate(B.x,B.y);x.rotate(a);x.scale(1.5,1.5);
x.fillStyle=`rgba(120,220,255,${.6+R()*.4})`;x.beginPath();x.moveTo(-10,-2.5);x.lineTo(-24-R()*10,0);x.lineTo(-10,2.5);x.fill();
x.fillStyle='#e8ecf5';x.beginPath();x.moveTo(-10,0);x.lineTo(-20,-6);x.lineTo(-17,0);x.lineTo(-20,6);x.closePath();x.fill();
[-1,1].forEach(sd=>{x.fillStyle='#3a4a70';x.beginPath();x.moveTo(5,0);x.quadraticCurveTo(-2,sd*(12+fl),-16,sd*(24+fl));x.lineTo(-11,sd*(15+fl*.6));x.lineTo(-14,sd*(17+fl*.7));x.lineTo(-8,sd*(8+fl*.3));x.lineTo(-5,0);x.fill();
x.strokeStyle='#ffd27a';x.lineWidth=1;x.beginPath();x.moveTo(3,sd*2);x.quadraticCurveTo(-2,sd*(10+fl),-15,sd*(22+fl));x.stroke()});
x.fillStyle='#c9d2e6';x.beginPath();x.ellipse(0,0,12,4.5,0,0,7);x.fill();x.fillStyle='#f0a030';x.beginPath();x.moveTo(11,-1.5);x.lineTo(18,0);x.lineTo(11,1.5);x.fill();
x.fillStyle='#4ff';x.beginPath();x.ellipse(5,-1.5,3,1.8,0,0,7);x.fill();x.restore()});
for(let i=spark.length-1;i>=0;i--){const p=spark[i];p.x+=p.vx;p.y+=p.vy;if(--p.l<0){spark.splice(i,1);continue}x.fillStyle=`rgba(255,240,150,${p.l/35})`;x.fillRect(p.x,p.y,3,3)}};
const upd=()=>h.classList.toggle('scene',on());upd();tick();document.addEventListener('click',()=>setTimeout(upd,0));matchMedia('(prefers-color-scheme: dark)').addEventListener('change',upd)})();
