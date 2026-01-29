# community-tournaments

Community-made single-elimination tournament platform built by players for players.  
No corporate bullshit. No paywalls.  
Pure PHP backend • MySQL • aggressive vanilla-JS SPA • Workerman websocket for live chat and notifications • BSC blockchain integration.

---

## What this repo is
A lightweight, community-first tournament platform:
- single-elimination brackets
- participant check-in & match reporting
- per-tournament live chat and notifications via Workerman 
- Integrated on-chain rewards using the Binance Smart Chain (BSC — BNB)
- pure PHP backend, MySQL, and a fast vanilla-JS SPA frontend

## Stack
- PHP (backend)
- MySQL (database)
- Vanilla JavaScript SPA (frontend)
- Workerman (WebSocket chat)

## Rewards (BNB / BSC)
- Players can withdraw winnings to their own BSC wallet addresses; payouts are recorded on-chain.  
- Each user may have an on-chain wallet address stored (optional). Platform keeps payout transaction logs for auditing.  
- This feature is toggleable — can be enabled for events that have sponsored or donated prizes, or disabled if you prefer not to use on-chain payouts.  
- Implementation notes (short): payouts are made via server-side transactions and recorded in the `transactions`/`payouts` logs; no card data or custodial payment rails are involved.  
- Do not commit private keys or secrets into the repo.

## Philosophy
Community-run. Minimal dependencies. Player-first. If you don’t know how to run a PHP project, this repo is not for you.

## Contributing
PRs welcome. Keep changes focused and don’t commit secrets.

## License
MIT
