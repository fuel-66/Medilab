let ACTIVE_CHAT = null;

function loadChats() {
    fetch('msg_fetch_chats.php')
        .then(r=>r.text())
        .then(html=> chatList.innerHTML = html);
}

function openChat(type,id,name) {
    ACTIVE_CHAT = {type,id};
    chatName.innerText = name;
    loadMessages();
}

function loadMessages() {
    if (!ACTIVE_CHAT) return;
    fetch(`msg_fetch_msgs.php?type=${ACTIVE_CHAT.type}&id=${ACTIVE_CHAT.id}`)
        .then(r=>r.text())
        .then(html=>{
            chatBody.innerHTML = html;
            chatBody.scrollTop = chatBody.scrollHeight;
        });
}

setInterval(()=> {
    loadChats();
    loadMessages();
}, 1500);

chatForm.onsubmit = e => {
    e.preventDefault();
    if (!msg.value || !ACTIVE_CHAT) return;

    fetch('msg_send.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`msg=${encodeURIComponent(msg.value)}&type=${ACTIVE_CHAT.type}&id=${ACTIVE_CHAT.id}`
    }).then(()=> msg.value="");
};

loadChats();
