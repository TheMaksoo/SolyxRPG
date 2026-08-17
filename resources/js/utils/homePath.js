// Dashboard (the Inn) is ungated (see navigation.js) but a level-1 character has nothing to do there
// yet — they land on Battle first, same as the tutorial's own opening line ("fight here"), and only
// get routed to the Inn once they've actually leveled up once.
export function homePathForLevel(level) {
  return (level ?? 0) >= 2 ? '/dashboard' : '/battle';
}

export function homePath(auth) {
  return homePathForLevel(auth.user?.character?.level);
}
