// SPDX-License-Identifier: MIT
pragma solidity 0.8.24;

import {PracticeMoney} from "../src/PracticeMoney.sol";

/// The few Foundry cheatcodes these tests use, declared here so the tests
/// need no forge-std dependency.
interface Vm {
    function expectRevert(bytes calldata revertData) external;
    function expectEmit(bool, bool, bool, bool, address) external;
    function prank(address sender) external;
    function assume(bool condition) external;
    function etch(address target, bytes calldata code) external;
}

/// Stands in for a deployed group fund: an address with contract code.
contract FakeFund {
    function send(PracticeMoney token, address to, uint256 amount) external returns (bool) {
        return token.transfer(to, amount);
    }
}

contract PracticeMoneyTest {
    Vm private constant vm = Vm(address(uint160(uint256(keccak256("hevm cheat code")))));

    event Funded(address indexed fund, uint256 amount);

    PracticeMoney private token;
    FakeFund private groupFund;
    address private constant MEMBER = address(0xBEEF);

    function setUp() public {
        token = new PracticeMoney();
        groupFund = new FakeFund();
    }

    function _eq(uint256 a, uint256 b, string memory what) private pure {
        require(a == b, what);
    }

    function test_nameSymbolDecimalsAndAmount() public view {
        require(keccak256(bytes(token.name())) == keccak256("Practice Money"), "name");
        require(keccak256(bytes(token.symbol())) == keccak256("PRACTICE"), "symbol");
        _eq(token.decimals(), 18, "decimals");
        _eq(token.PRACTICE_AMOUNT(), 1_000 ether, "amount is 1,000 with 18 decimals");
        _eq(token.totalSupply(), 0, "nothing exists before a fund is funded");
    }

    function test_fundGivesAGroupFund1000Once() public {
        vm.expectEmit(true, false, false, true, address(token));
        emit Funded(address(groupFund), 1_000 ether);
        // Anyone may call it: here a member with no special role.
        vm.prank(MEMBER);
        token.fund(address(groupFund));

        _eq(token.balanceOf(address(groupFund)), 1_000 ether, "fund balance");
        _eq(token.totalSupply(), 1_000 ether, "supply");
        _eq(token.balanceOf(MEMBER), 0, "the caller gets nothing");
        require(token.funded(address(groupFund)), "recorded as funded");
    }

    function test_secondCallForTheSameFundReverts() public {
        token.fund(address(groupFund));
        vm.expectRevert(abi.encodeWithSelector(PracticeMoney.AlreadyFunded.selector, address(groupFund)));
        token.fund(address(groupFund));
        _eq(token.balanceOf(address(groupFund)), 1_000 ether, "still 1,000");
    }

    function test_spendingItDoesNotOpenASecondFunding() public {
        token.fund(address(groupFund));
        groupFund.send(token, MEMBER, 1_000 ether);
        _eq(token.balanceOf(address(groupFund)), 0, "spent");
        vm.expectRevert(abi.encodeWithSelector(PracticeMoney.AlreadyFunded.selector, address(groupFund)));
        token.fund(address(groupFund));
    }

    function test_eachFundIsFundedSeparately() public {
        FakeFund other = new FakeFund();
        token.fund(address(groupFund));
        token.fund(address(other));
        _eq(token.balanceOf(address(other)), 1_000 ether, "second fund");
        _eq(token.totalSupply(), 2_000 ether, "supply is 1,000 per fund");
    }

    function test_plainWalletIsRefused() public {
        vm.expectRevert(abi.encodeWithSelector(PracticeMoney.NotAContract.selector, MEMBER));
        token.fund(MEMBER);
        require(!token.funded(MEMBER), "not recorded");
        _eq(token.totalSupply(), 0, "nothing minted");
    }

    function test_zeroAddressIsRefused() public {
        vm.expectRevert(abi.encodeWithSelector(PracticeMoney.NotAContract.selector, address(0)));
        token.fund(address(0));
    }

    /// The token contract holds code but must not fund itself: nothing
    /// could ever move that balance.
    function test_tokenItselfIsRefused() public {
        vm.expectRevert(abi.encodeWithSelector(PracticeMoney.TokenItself.selector));
        token.fund(address(token));
        require(!token.funded(address(token)), "not recorded");
        _eq(token.totalSupply(), 0, "nothing minted");
    }

    /// A refused address can be funded once it holds code, e.g. a fund
    /// whose address was known before it was deployed.
    function test_addressFundedOnceItHoldsCode() public {
        address later = address(0xF00D);
        vm.expectRevert(abi.encodeWithSelector(PracticeMoney.NotAContract.selector, later));
        token.fund(later);
        vm.etch(later, address(groupFund).code);
        token.fund(later);
        _eq(token.balanceOf(later), 1_000 ether, "funded after deploy");
    }

    function testFuzz_noCodeNoFunding(address who) public {
        vm.assume(who.code.length == 0);
        vm.expectRevert(abi.encodeWithSelector(PracticeMoney.NotAContract.selector, who));
        token.fund(who);
    }

    /// No owner or minting function exists: each of these calls reverts
    /// (the contract has no fallback).
    function test_noOwnerOrMintFunctions() public {
        bytes[6] memory calls = [
            abi.encodeWithSignature("owner()"),
            abi.encodeWithSignature("mint(address,uint256)", MEMBER, 1),
            abi.encodeWithSignature("transferOwnership(address)", MEMBER),
            abi.encodeWithSignature("renounceOwnership()"),
            abi.encodeWithSignature("pause()"),
            abi.encodeWithSignature("upgradeToAndCall(address,bytes)", MEMBER, "")
        ];
        for (uint256 i = 0; i < calls.length; i++) {
            (bool ok,) = address(token).call(calls[i]);
            require(!ok, "an admin function answered");
        }
    }

    /// The only state-changing entry points are fund() and the ERC-20 ones.
    function test_onlyMintPathIsFund() public {
        token.fund(address(groupFund));
        groupFund.send(token, MEMBER, 10 ether);
        _eq(token.balanceOf(MEMBER), 10 ether, "a payment of 10 moves 10");
        _eq(token.totalSupply(), 1_000 ether, "transfers mint nothing");
    }
}
