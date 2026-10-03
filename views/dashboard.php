<div class="page-head">
    <div>
        <h1>Good to see you, <?= e(explode(' ', $user['name'])[0]) ?></h1>
        <p class="muted">The tracker foundation is running. Modules come online as they're built.</p>
    </div>
</div>

<div class="grid-cards">
    <div class="card card-module">
        <span class="chip chip-orange">Next up</span>
        <h2>Projects</h2>
        <p>Pipeline from contract signed to PTO, milestones with needed / date / note, equipment, batteries, and the running project log.</p>
    </div>
    <div class="card card-module">
        <span class="chip">Planned</span>
        <h2>Service</h2>
        <p>Service tickets per customer with visits, trips and hours, warranty or billable, and the same log.</p>
    </div>
    <div class="card card-module">
        <span class="chip">Planned</span>
        <h2>Action Items</h2>
        <p>Assignable to-dos for the team, separate from the project flow.</p>
    </div>
    <div class="card card-module">
        <span class="chip">Planned</span>
        <h2>Reports</h2>
        <p>Sales by salesperson (count, dollars, kW), time to PTO, and forward projections.</p>
    </div>
</div>
