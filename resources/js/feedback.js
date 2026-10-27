let audioContext=null;
function initializeAudio(){if(document.documentElement.dataset.uiSound!=='true')return;try{audioContext??=new (window.AudioContext||window.webkitAudioContext)();audioContext.resume().catch(()=>{});}catch{}}
document.addEventListener('pointerdown',initializeAudio,{passive:true});
document.addEventListener('moto:feedback',event=>{
    if(!event.detail?.test&&document.documentElement.dataset.uiSound!=='true')return;
    try{
        audioContext??=new (window.AudioContext||window.webkitAudioContext)();const oscillator=audioContext.createOscillator(),gain=audioContext.createGain();const time=audioContext.currentTime;
        oscillator.type='sine';oscillator.frequency.setValueAtTime(event.detail?.tone==='error'?220:660,time);oscillator.frequency.exponentialRampToValueAtTime(event.detail?.tone==='error'?165:880,time+0.12);
        gain.gain.setValueAtTime(0.035,time);gain.gain.exponentialRampToValueAtTime(0.0001,time+0.18);oscillator.connect(gain);gain.connect(audioContext.destination);oscillator.start(time);oscillator.stop(time+0.18);
    }catch{}
});
