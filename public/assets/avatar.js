/* CSS owns eyelids and generic talking; JavaScript only selects states and expressions. */
(() => {
  const A=window.PHiLLiP,classes=['thinking','happy','sad','angry','surprised','bsod','love','wink','eyeroll','cool','party','sleepy','confused'];
  let buffer='',emotion='',lastText=0,timer,thinking=false;
  const paint=()=>{
    document.querySelectorAll('.avatar').forEach(node=>{
      classes.forEach(name=>node.classList.toggle(name,name===(emotion||(thinking?'thinking':''))));
    });
    const companion=document.querySelector('.chat-companion');
    if(!companion)return;
    companion.classList.toggle('reacting',Boolean(emotion));
    companion.querySelector('.companion-bubble').textContent=({bsod:'Thought overflow. Rebooting…',happy:'Oh, I like this!',sad:'I’m here with you.',angry:'Let’s sort this out.',surprised:'Well, would you look at that!',love:'A little warmth goes a long way.',wink:'Between you and me…',eyeroll:'Well… that happened.',cool:'All in a day’s work.',party:'A small victory deserves a celebration!',sleepy:'Just resting my pixels.',confused:'Let me tilt my head at that.'})[emotion]||'Right here with you.';
    companion.querySelector('.companion-status').textContent=A.avatarTalking?'Thinking out loud…':thinking?'Mulling it over…':'PHiLLiP';
  };
  A.setTalking=active=>{
    A.avatarTalking=Boolean(active);
    document.querySelectorAll('.avatar').forEach(node=>node.classList.toggle('is-talking',A.avatarTalking));
    paint();
  };
  A.emote=(text='',activity='')=>{
    thinking=activity==='thinking';
    if(text){
      const now=Date.now();if(now-lastText>3000)buffer='';lastText=now;buffer=(buffer+text).slice(-240);
      const matches=[...buffer.matchAll(/mind[- ]blowing|mind.{0,12}blown|🤯|❤️|♥|🥰|😍|love|😉|wink|😊|😄|happy|wonderful|delighted|😢|😔|sorry|sad|disappoint|😠|😡|angry|frustrat|😮|😲|surpris|wow|🙄|😎|🎉|🥳|😴|🥱|🤔|😕/gi)],last=matches.at(-1)?.[0]||'';
      const mood=/mind|🤯/i.test(last)?'bsod':/❤️|♥|🥰|😍|love/i.test(last)?'love':/😉|wink/i.test(last)?'wink':/😊|😄|happy|wonderful|delighted/i.test(last)?'happy':/😢|😔|sorry|sad|disappoint/i.test(last)?'sad':/😠|😡|angry|frustrat/i.test(last)?'angry':/😮|😲|surpris|wow/i.test(last)?'surprised':({'🙄':'eyeroll','😎':'cool','🎉':'party','🥳':'party','😴':'sleepy','🥱':'sleepy','🤔':'confused','😕':'confused'})[last]||'';
      if(mood){emotion=mood;clearTimeout(timer);timer=setTimeout(()=>{emotion='';paint();},mood==='bsod'?3200:2200);}
    }
    paint();
  };
  A.resetAvatar=()=>{clearTimeout(timer);buffer='';emotion='';thinking=false;A.setTalking(false);};
  window.addEventListener('pagehide',A.resetAvatar);
})();
